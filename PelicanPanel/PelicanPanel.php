<?php

/*
 * Pelican Panel for Paymenter
 *
 * A Paymenter server extension that creates, suspends, upgrades and deletes game servers on Pelican Panel
 * (pelican.dev). Pelican started as a fork of Pterodactyl, so this extension starts from Paymenter's Pterodactyl
 * extension and follows Pelican's Application API where it differs: eggs are listed directly and grouped by tags
 * (there are no nests), nodes are chosen by node tags (there are no locations), and the extension picks the
 * allocation itself, because Pelican's automatic deployment gives a server no port unless a port range is sent.
 *
 * Based on the built-in Pterodactyl extension from Paymenter (MIT, Copyright (c) Paymenter).
 * Changes Copyright (c) 2026 Karelia Labs. Released under the MIT License, see LICENSE.
 */

namespace Paymenter\Extensions\Servers\PelicanPanel;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use App\Models\User;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

#[ExtensionMeta(
    name: 'Pelican Panel',
    description: 'Creates, suspends, upgrades and deletes game servers on Pelican Panel.',
    version: '1.0.0',
    author: 'Karelia Labs',
    url: 'https://karelialabs.com',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIGZpbGw9IiMwRTc0OTAiLz48dGV4dCB4PSIzMiIgeT0iNDEiIGZvbnQtZmFtaWx5PSJBcmlhbCxIZWx2ZXRpY2Esc2Fucy1zZXJpZiIgZm9udC1zaXplPSIyNCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+UEU8L3RleHQ+PC9zdmc+',
)]
class PelicanPanel extends Server
{
    /** Where the key is made, for error messages. */
    private const KEY_PAGE = 'Pelican: Admin → Advanced → API Keys';

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Pelican Panel URL',
                'type' => 'text',
                'description' => 'The address you open Pelican at, for example https://panel.example.com. The "Go to server" button links here.',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Application API key',
                'type' => 'password',
                'description' => self::KEY_PAGE . '. Read & Write for Server and User, Read for Node, Allocation and Egg.',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    /**
     * Paymenter calls this after a server is created in the admin. Paymenter 1.5.9 saves that first form without
     * the settings' "encrypted" flag (a later save sets it), so the key is encrypted here right away.
     */
    public function enabled(\App\Models\Server $server): void
    {
        $setting = $server->settings()->where('key', 'api_key')->first();
        if ($setting && !$setting->encrypted) {
            $setting->encrypted = true;
            $setting->save();
        }
    }

    /**
     * Checks the URL, the key and every read permission the extension needs. Write permissions cannot be checked
     * without changing something in the panel; a missing one shows up as a clear error on the first order.
     */
    public function testConfig(): bool|string
    {
        try {
            $servers = $this->request('/api/application/servers', 'get', ['per_page' => 1]);
            if (($servers['object'] ?? null) !== 'list') {
                throw new Exception('This address does not look like a Pelican Panel: check the Pelican Panel URL.');
            }
            $this->request('/api/application/users', 'get', ['per_page' => 1]);
            $this->request('/api/application/eggs');
            $nodes = $this->request('/api/application/nodes', 'get', ['per_page' => 1]);
            if (isset($nodes['data'][0]['attributes']['id'])) {
                $this->request('/api/application/nodes/' . $nodes['data'][0]['attributes']['id'] . '/allocations', 'get', ['per_page' => 1]);
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function request(string $path, string $method = 'get', array $data = []): array
    {
        $method = strtolower($method);
        $response = $this->send($path, $method, $data);

        if (!$response->successful()) {
            throw new Exception($this->errorMessage($response, $method, $path));
        }
        if ($response->status() === 204) {
            return [];
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new Exception('This address does not look like a Pelican Panel (' . strtoupper($method) . ' ' . $path . ' returned no JSON): check the Pelican Panel URL.');
        }

        return $json;
    }

    public function getProductConfig($values = []): array
    {
        $eggs = [];
        foreach ($this->all('/api/application/eggs', ['include' => 'variables']) as $egg) {
            $eggs[$egg['attributes']['id']] = $egg['attributes'];
        }

        $eggTags = [];
        $eggList = [];
        $selectedTag = (string) ($values['egg_tag'] ?? '');
        foreach ($eggs as $id => $egg) {
            $tags = (array) ($egg['tags'] ?? []);
            foreach ($tags as $tag) {
                $eggTags[$tag] = $tag;
            }
            if ($selectedTag === '' || in_array($selectedTag, $tags, true)) {
                $eggList[$id] = $egg['name'];
            }
        }
        ksort($eggTags);

        // Keep the saved egg in the list even when the tag filter hides it or it was deleted in Pelican.
        $selectedEgg = isset($values['egg_id']) && $values['egg_id'] !== '' ? $eggs[$values['egg_id']] ?? null : null;
        if ($selectedEgg) {
            $eggList[$selectedEgg['id']] = $selectedEgg['name'];
        } elseif (!empty($values['egg_id'])) {
            $eggList[$values['egg_id']] = 'Egg #' . $values['egg_id'] . ' (not found in Pelican)';
        }

        $nodeList = [];
        $nodeTags = [];
        foreach ($this->all('/api/application/nodes') as $node) {
            $nodeList[$node['attributes']['id']] = $node['attributes']['name'];
            foreach ((array) ($node['attributes']['tags'] ?? []) as $tag) {
                $nodeTags[$tag] = $tag;
            }
        }
        ksort($nodeTags);

        $usingPortArray = isset($values['port_array']) && $values['port_array'] !== '';

        return [
            [
                'name' => 'egg_tag',
                'label' => 'Egg tag',
                'type' => 'select',
                'options' => $eggTags,
                'description' => 'Pelican groups eggs with tags instead of nests. Pick a tag to narrow down the egg list, or leave it empty to list every egg.',
                'live' => true,
            ],
            [
                'name' => 'egg_id',
                'label' => 'Egg',
                'type' => 'select',
                'options' => $eggList,
                'required' => true,
                'live' => true,
            ],
            [
                'name' => 'docker_image',
                'label' => 'Docker image',
                'type' => 'select',
                'options' => $selectedEgg ? $this->imageOptions($selectedEgg) : [],
                'description' => 'Leave empty for the egg\'s first image.',
            ],
            ...($selectedEgg ? $this->variableFields($selectedEgg) : []),
            [
                'name' => 'node',
                'label' => 'Node',
                'type' => 'select',
                'options' => $nodeList,
                'description' => 'Fill in to always use this node. Leave empty to use any node with "Use for Deployments" on and enough free memory, disk and CPU.',
            ],
            [
                'name' => 'node_tags',
                'label' => 'Node tags',
                'type' => 'select',
                'options' => $nodeTags,
                'multiple' => true,
                'database_type' => 'array',
                'description' => 'Only use nodes with at least one of these tags. Not used when a node is set.',
            ],
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'validation' => 'numeric',
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'swap',
                'label' => 'Swap',
                'type' => 'number',
                'min_value' => -1,
                'suffix' => 'MiB',
                'required' => true,
                'description' => 'Set to -1 for unlimited, or to 0 to disable swap',
            ],
            [
                'name' => 'disk',
                'label' => 'Disk',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'io',
                'label' => 'IO Weight',
                'type' => 'number',
                'required' => true,
                'default' => 500,
                'min_value' => 10,
                'max_value' => 1000,
                'description' => 'The IO Weight is the priority given to this server for disk access.',
                'hint' => new HtmlString('<a href="https://docs.docker.com/engine/reference/run/#block-io-bandwidth-blkio-constraint" target="_blank">Documentation</a>'),
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Limit',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'suffix' => '%',
                'description' => 'Set to 0 for unlimited. 100 is one core.',
            ],
            [
                'name' => 'cpu_pinning',
                'label' => 'CPU Pinning',
                'type' => 'text',
                'description' => 'Leave empty for no pinning. Used to specify what threads should be used. Example: 0,2-4,5,6',
                'validation' => 'regex:/^[0-9]+(?:-[0-9]+)?(?:,[0-9]+(?:-[0-9]+)?)*$/',
            ],
            [
                'name' => 'databases',
                'label' => 'Databases',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'backups',
                'label' => 'Backups',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'additional_allocations',
                'label' => 'Additional Allocations',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'description' => 'Ports the customer may add in Pelican on top of the ones the server gets.',
            ],
            [
                'name' => 'port_array',
                'label' => 'Port Array',
                'type' => 'text',
                'description' => 'Optional. Assigns ports to egg variables, for example {"SERVER_PORT": 2456, "QUERY_PORT": 2457, "NONE": [2458]}.',
                'live' => true,
                'validation' => 'json',
            ],
            [
                'name' => 'port_range',
                'label' => 'Port ranges',
                'type' => 'tags',
                'description' => 'Only use these ports: single ports or ranges like 25565-25600. Leave empty to use any free port.',
                'database_type' => 'array',
                'nested_validation' => ['regex:/^\d{1,5}(-\d{1,5})?$/'],
                'disabled' => $usingPortArray,
            ],
            [
                'name' => 'skip_scripts',
                'label' => 'Skip Egg Install Script',
                'description' => 'If the selected Egg has an install script attached to it, the script will run during the install. If you would like to skip this step, check this box.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'dedicated_ip',
                'label' => 'Dedicated IP',
                'description' => 'Only use ports on an IP address that no other server on the node uses.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'start_on_completion',
                'label' => 'Start on completion',
                'description' => 'Start server automatically after installation.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'oom_killer',
                'label' => 'Enable OOM Killer',
                'description' => 'Terminates the server if it breaches the memory limits. Enabling OOM killer may cause server processes to exit unexpectedly.',
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * The egg's images as select options: image => "Label (image)".
     */
    private function imageOptions(array $egg): array
    {
        $options = [];
        foreach ((array) ($egg['docker_images'] ?? []) as $label => $image) {
            $options[$image] = is_string($label) && $label !== $image ? $label . ' (' . $image . ')' : $image;
        }

        return $options;
    }

    /**
     * One text field per egg variable. A config option with the variable's name still takes precedence.
     */
    private function variableFields(array $egg): array
    {
        $fields = [];
        foreach ($this->variables($egg) as $variable) {
            $description = trim((string) ($variable['description'] ?? ''));
            if ($description !== '' && !preg_match('/[.!?]$/', $description)) {
                $description .= '.';
            }
            $fields[] = [
                'name' => 'env_' . $variable['env_variable'],
                'label' => $variable['name'] . ' (' . $variable['env_variable'] . ')',
                'type' => 'text',
                'placeholder' => (string) ($variable['default_value'] ?? ''),
                'description' => ltrim($description . ' Leave empty for the egg\'s default.'),
            ];
        }

        return $fields;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->findServer($service->id)) {
            throw new Exception('Pelican already has a server for this service (external ID ' . $service->id . ').');
        }
        $settings = array_merge($settings, $properties);

        $egg = $this->egg($settings);
        $deployment = $this->deployment($settings, $this->environment($egg, $settings));

        $serverData = [
            'external_id' => (string) $service->id,
            'name' => $settings['servername'] ?? $service->product->name . ' #' . $service->id,
            'user' => $this->panelUser($service->user),
            'egg' => (int) $egg['id'],
            'environment' => $deployment['environment'],
            'skip_scripts' => (bool) ($settings['skip_scripts'] ?? false),
            'oom_killer' => (bool) ($settings['oom_killer'] ?? false),
            'start_on_completion' => (bool) ($settings['start_on_completion'] ?? false),
            'limits' => $this->limits($settings),
            'feature_limits' => $this->featureLimits($settings),
            'allocation' => $deployment['allocation'],
        ];
        if (!empty($settings['docker_image'])) {
            $serverData['docker_image'] = $settings['docker_image'];
        }

        $server = $this->request('/api/application/servers', 'post', $serverData);

        return [
            'server' => $server['attributes']['id'],
            'link' => $this->serverUrl($server['attributes']['identifier']),
        ];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->request('/api/application/servers/' . $this->server($service->id)['id'] . '/suspend', 'post');

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->request('/api/application/servers/' . $this->server($service->id)['id'] . '/unsuspend', 'post');

        return true;
    }

    /**
     * A server that is already gone from Pelican counts as terminated.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $server = $this->findServer($service->id);
        if ($server) {
            $this->request('/api/application/servers/' . $server['id'], 'delete');
        }

        return true;
    }

    /**
     * New resources for the same server. Egg variables keep the values the server already has; a new egg brings
     * its own startup command and image unless the product names an image.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $server = $this->server($service->id);
        $settings = array_merge($settings, $properties);

        $this->request('/api/application/servers/' . $server['id'] . '/build', 'patch', [
            'allocation' => $server['allocation'],
            'oom_killer' => (bool) ($settings['oom_killer'] ?? false),
            'limits' => $this->limits($settings),
            'feature_limits' => $this->featureLimits($settings),
        ]);

        $egg = $this->egg($settings);
        $startup = [
            'egg' => (int) $egg['id'],
            'environment' => $this->environment($egg, $settings, (array) ($server['container']['environment'] ?? [])),
            'skip_scripts' => (bool) ($settings['skip_scripts'] ?? false),
        ];
        if ((int) $egg['id'] !== (int) $server['egg'] && !empty($settings['docker_image'])) {
            $startup['image'] = $settings['docker_image'];
        }
        $this->request('/api/application/servers/' . $server['id'] . '/startup', 'patch', $startup);

        return true;
    }

    public function getActions(Service $service)
    {
        return [
            [
                'type' => 'button',
                'label' => 'Go to server',
                'url' => $this->serverUrl($this->server($service->id)['identifier']),
            ],
        ];
    }

    /**
     * The customer's page of a server in Pelican. Its identifier is the server's short UUID.
     */
    private function serverUrl(string $identifier): string
    {
        return rtrim((string) $this->config('host'), '/') . '/server/' . $identifier;
    }

    private function limits(array $settings): array
    {
        return [
            'memory' => (int) ($settings['memory'] ?? 0),
            'swap' => (int) ($settings['swap'] ?? 0),
            'disk' => (int) ($settings['disk'] ?? 0),
            'io' => (int) ($settings['io'] ?? 500),
            'cpu' => (int) ($settings['cpu'] ?? 0),
            'threads' => !empty($settings['cpu_pinning']) ? (string) $settings['cpu_pinning'] : null,
        ];
    }

    /**
     * Pelican's allocation limit counts every port of the server, so the ports it gets at creation are included.
     */
    private function featureLimits(array $settings): array
    {
        return [
            'databases' => (int) ($settings['databases'] ?? 0),
            'backups' => (int) ($settings['backups'] ?? 0),
            'allocations' => $this->portCount($this->portArray($settings) ?? ['SERVER_PORT' => 0]) + (int) ($settings['additional_allocations'] ?? 0),
        ];
    }

    private function portCount(array $ports): int
    {
        return array_sum(array_map(fn ($value) => is_array($value) ? count($value) : 1, $ports));
    }

    /**
     * The egg with its variables.
     */
    private function egg(array $settings): array
    {
        if (empty($settings['egg_id'])) {
            throw new Exception('The product has no egg selected (Admin → Products → the product → Server).');
        }

        return $this->request('/api/application/eggs/' . (int) $settings['egg_id'], 'get', ['include' => 'variables'])['attributes'];
    }

    private function variables(array $egg): array
    {
        $variables = array_column($egg['relationships']['variables']['data'] ?? [], 'attributes');
        usort($variables, fn ($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        return $variables;
    }

    /**
     * Values for the egg's variables, first found wins: the server's current value (upgrades), a config option or
     * service property with the variable's name, the product's value, the egg's default.
     */
    private function environment(array $egg, array $settings, array $current = []): array
    {
        $environment = [];
        foreach ($this->variables($egg) as $variable) {
            $name = $variable['env_variable'];
            $productValue = $settings['env_' . $name] ?? null;
            $environment[$name] = $current[$name]
                ?? $settings[$name]
                ?? ($productValue !== null && $productValue !== '' ? $productValue : $variable['default_value']);
        }

        return $environment;
    }

    /**
     * The Pelican user with the customer's email address, created if there is none. Pelican's email filter also
     * matches parts of addresses, so every page of matches is read and the whole address compared.
     */
    private function panelUser(User $user): int
    {
        foreach ($this->all('/api/application/users', ['filter' => ['email' => $user->email]]) as $panelUser) {
            if (strcasecmp($panelUser['attributes']['email'], $user->email) === 0) {
                return (int) $panelUser['attributes']['id'];
            }
        }

        $username = (preg_replace('/[^a-z0-9]/', '', strtolower(Str::transliterate((string) $user->name))) ?: Str::lower(Str::random(8)))
            . '_' . Str::lower(Str::random(4));

        // Without a password Pelican emails the user a link to set one.
        return (int) $this->request('/api/application/users', 'post', [
            'email' => $user->email,
            'username' => $username,
        ])['attributes']['id'];
    }

    /**
     * Picks the node and the allocation(s). Pelican's own automatic deployment only gives a server a port when a
     * port range is sent, so the extension chooses from the nodes Pelican reports as deployable.
     */
    private function deployment(array $settings, array $environment): array
    {
        $portArray = $this->portArray($settings);
        $ports = $portArray ?? ['SERVER_PORT' => 0];
        $needed = $this->portCount($ports);

        $nodeId = (int) ($settings['node'] ?? 0);
        $tags = $nodeId ? [] : $this->arraySetting($settings['node_tags'] ?? []);
        $query = [
            'memory' => (int) ($settings['memory'] ?? 0),
            'disk' => (int) ($settings['disk'] ?? 0),
            'cpu' => (int) ($settings['cpu'] ?? 0),
            'include' => 'allocations',
        ];
        if ($tags) {
            $query['tags'] = $tags;
        }
        $nodes = $this->request('/api/application/nodes/deployable', 'get', $query)['data'] ?? [];

        if ($nodeId) {
            $nodes = array_filter($nodes, fn ($node) => (int) $node['attributes']['id'] === $nodeId);
            if (!$nodes) {
                throw new Exception($this->unusableNode($nodeId, $query));
            }
        } elseif (!$nodes) {
            throw new Exception('No node can take this server: Pelican has no node with "Use for Deployments" on and '
                . $this->resources($query) . ' free' . ($tags ? ' that has one of the tags ' . implode(', ', $tags) : '') . '.');
        }

        $ranges = $portArray === null ? $this->portRanges($settings['port_range'] ?? []) : [];
        foreach ($nodes as $node) {
            $free = $this->freeAllocations($node, $ranges, (bool) ($settings['dedicated_ip'] ?? false));
            if (count($free) >= $needed) {
                return $this->assignPorts($ports, $free, $environment, $portArray !== null);
            }
        }

        throw new Exception('Not enough free allocations: the server needs ' . $needed . ' free port' . ($needed > 1 ? 's' : '')
            . ($ranges ? ' in ' . implode(', ', $this->arraySetting($settings['port_range'])) : '')
            . (!empty($settings['dedicated_ip']) ? ' on an IP address no other server uses' : '')
            . ' on ' . ($nodeId ? 'the product\'s node' : 'one node') . '. Add allocations to the node in Pelican.');
    }

    /**
     * Why a product's node cannot take the server, for the error message.
     */
    private function unusableNode(int $nodeId, array $query): string
    {
        try {
            $node = $this->request('/api/application/nodes/' . $nodeId)['attributes'];
        } catch (Exception $e) {
            return 'The product\'s node #' . $nodeId . ' cannot be used: ' . $e->getMessage();
        }

        if (empty($node['public'])) {
            return 'Node "' . $node['name'] . '" has "Use for Deployments" turned off in Pelican. Turn it on, or choose another node in the product.';
        }

        return 'Node "' . $node['name'] . '" does not have ' . $this->resources($query) . ' free (Pelican counts the node\'s overallocation).';
    }

    private function resources(array $query): string
    {
        return $query['memory'] . ' MiB memory, ' . $query['disk'] . ' MiB disk and ' . $query['cpu'] . '% CPU';
    }

    /**
     * The node's unassigned allocations, limited to the port ranges and, for a dedicated IP, to addresses no other
     * server on the node uses. Sorted by port.
     */
    private function freeAllocations(array $node, array $ranges, bool $dedicatedIp): array
    {
        $allocations = $node['attributes']['relationships']['allocations']['data'] ?? null;
        if (!is_array($allocations)) {
            throw new Exception('The API key needs "Read" for Allocation (' . self::KEY_PAGE . ').');
        }
        $allocations = array_column($allocations, 'attributes');

        $usedIps = $dedicatedIp ? array_unique(array_column(array_filter($allocations, fn ($a) => $a['assigned']), 'ip')) : [];

        $free = array_filter($allocations, function ($allocation) use ($ranges, $usedIps) {
            if ($allocation['assigned'] || in_array($allocation['ip'], $usedIps, true)) {
                return false;
            }
            foreach ($ranges as [$from, $to]) {
                if ($allocation['port'] >= $from && $allocation['port'] <= $to) {
                    return true;
                }
            }

            return $ranges === [];
        });
        usort($free, fn ($a, $b) => [$a['port'], $a['id']] <=> [$b['port'], $b['id']]);

        return $free;
    }

    /**
     * Gives every entry of the port array a free allocation: the same port if it is free, else the next higher
     * free port, else the lowest free one. Without a port array the server gets the lowest free port.
     */
    private function assignPorts(array $ports, array $free, array $environment, bool $fromPortArray): array
    {
        $free = collect($free);
        $assigned = [];
        foreach ($ports as $key => $value) {
            foreach ((array) $value as $port) {
                $allocation = $free->firstWhere('port', (int) $port)
                    ?? $free->first(fn ($a) => $a['port'] > (int) $port)
                    ?? $free->first();
                $free = $free->reject(fn ($a) => $a['id'] === $allocation['id']);
                $assigned[$key][] = $allocation;
            }
        }

        $additional = [];
        foreach ($assigned as $key => $allocations) {
            if ($key === 'SERVER_PORT') {
                continue;
            }
            $additional = array_merge($additional, array_column($allocations, 'id'));
            // A single port named after an egg variable is written to that variable.
            if ($fromPortArray && $key !== 'NONE' && !is_array($ports[$key]) && array_key_exists($key, $environment)) {
                $environment[$key] = (string) $allocations[0]['port'];
            }
        }

        return [
            'environment' => $environment,
            'allocation' => [
                'default' => $assigned['SERVER_PORT'][0]['id'],
                'additional' => $additional,
            ],
        ];
    }

    /**
     * The product's port array, or null when it has none.
     * Example: {"SERVER_PORT": 2456, "QUERY_PORT": 2457, "NONE": [2458, 2459]}
     */
    private function portArray(array $settings): ?array
    {
        $raw = $settings['port_array'] ?? '';
        if ($raw === '' || $raw === null) {
            return null;
        }

        $ports = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $valid = is_array($ports) && isset($ports['SERVER_PORT']) && !is_array($ports['SERVER_PORT']);
        foreach ($valid ? $ports : [] as $value) {
            foreach ((array) $value as $port) {
                $valid = $valid && is_numeric($port) && (int) $port >= 1 && (int) $port <= 65535;
            }
        }
        if (!$valid) {
            throw new Exception('The product\'s port array must be JSON with one SERVER_PORT and port numbers, for example {"SERVER_PORT": 2456, "QUERY_PORT": 2457, "NONE": [2458]}.');
        }

        return $ports;
    }

    /**
     * Port range entries ("25565" or "25565-25600") as [from, to] pairs.
     */
    private function portRanges($value): array
    {
        $ranges = [];
        foreach ($this->arraySetting($value) as $entry) {
            if (!preg_match('/^\s*(\d{1,5})\s*(?:-\s*(\d{1,5})\s*)?$/', (string) $entry, $match)) {
                throw new Exception('Port range "' . $entry . '" is neither a port nor a range like 25565-25600.');
            }
            $ranges[] = [(int) $match[1], (int) ($match[2] ?? $match[1])];
        }

        return $ranges;
    }

    private function findServer($serviceId): ?array
    {
        $path = '/api/application/servers/external/' . $serviceId;
        $response = $this->send($path, 'get');
        if ($response->status() === 404) {
            return null;
        }
        if (!$response->successful()) {
            throw new Exception($this->errorMessage($response, 'get', $path));
        }

        return $response->json('attributes');
    }

    private function server($serviceId): array
    {
        return $this->findServer($serviceId)
            ?? throw new Exception('Pelican has no server for this service (external ID ' . $serviceId . '). It may have been deleted in Pelican.');
    }

    /**
     * Every item of a list endpoint, page by page (Pelican returns 50 per page unless asked for more).
     */
    private function all(string $path, array $query = []): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->request($path, 'get', array_merge($query, ['per_page' => 100, 'page' => $page]));
            $items = array_merge($items, $response['data'] ?? []);
            $pages = (int) ($response['meta']['pagination']['total_pages'] ?? 1);
        } while (++$page <= $pages);

        return $items;
    }

    /**
     * Product settings of type array arrive as arrays or as JSON text, depending on where they come from.
     */
    private function arraySetting($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
        }

        return array_values(array_filter((array) $value, fn ($item) => $item !== null && $item !== ''));
    }

    private function send(string $path, string $method = 'get', array $data = []): Response
    {
        $host = rtrim((string) $this->config('host'), '/');

        try {
            return Http::withToken((string) $this->config('api_key'))
                ->acceptJson()
                ->timeout(30)
                ->$method($host . $path, $data);
        } catch (ConnectionException $e) {
            throw new Exception('Could not connect to ' . $host . ': ' . $e->getMessage());
        }
    }

    /**
     * "Pelican answered HTTP 403 to GET /api/application/nodes: This action is unauthorized. The API key needs ..."
     */
    private function errorMessage(Response $response, string $method, string $path): string
    {
        $json = $response->json();
        $details = [];
        foreach (is_array($json) ? (array) ($json['errors'] ?? []) : [] as $error) {
            if (!empty($error['detail'])) {
                $details[] = $error['detail'];
            }
        }
        if (!$details && is_array($json) && !empty($json['message'])) {
            $details[] = $json['message'];
        }

        $message = 'Pelican answered HTTP ' . $response->status() . ' to ' . strtoupper($method) . ' ' . $path
            . ($details ? ': ' . implode(' ', array_unique($details)) : '');

        if ($response->status() === 401) {
            $message .= ' The API key was not accepted: copy the whole key, and use an Application API key (' . self::KEY_PAGE . ').';
        } elseif ($response->status() === 403 && $details === ['This action is unauthorized.']) {
            // Pelican's key form names the permissions in the singular: Server, User, Node, Allocation, Egg.
            $resource = str_contains($path, '/allocations') ? 'Allocation' : ucfirst(rtrim(explode('/', $path)[3] ?? '', 's'));
            $message .= ' The API key needs "' . ($method === 'get' ? 'Read' : 'Read & Write') . '" for ' . $resource . ' (' . self::KEY_PAGE . ').';
        } elseif ($response->status() === 404 && preg_match('#^/api/application/[a-z]+$#', $path)) {
            $message .= ' This address does not look like a Pelican Panel: check the Pelican Panel URL.';
        }

        return $message;
    }
}
