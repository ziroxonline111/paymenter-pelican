# Changelog

## 1.0.0 (2026-10-25)

First release.

- Creates game servers on Pelican Panel when an order is paid, and suspends, unsuspends, upgrades and deletes them
  with Paymenter's own jobs and the admin's "Trigger Extension Action".
- Server settings: the panel URL and an Application API key, stored encrypted, also right after the server is
  created (Paymenter 1.5.9 otherwise encrypts it only on the next save). Test Connection checks the URL, the key and
  every read permission the extension needs, and names the missing one.
- Eggs are read straight from Pelican (no nests) and can be narrowed down by egg tag. The product page shows the
  egg's Docker images and one field per egg variable, with the egg's default as a hint.
- Nodes are chosen by node tags (Pelican has no locations) or set per product. The extension picks the node and the
  port itself from the nodes Pelican reports as deployable, so every server gets a port; Pelican's own automatic
  deployment only gives one when a port range is sent.
- Port ranges, port arrays (ports written to egg variables, for example a query port) and dedicated IP addresses.
- Every page of nodes and users is read. The customer's Pelican account is found by the whole email address,
  because Pelican's email filter also matches parts of addresses; if there is none, one is created and Pelican
  emails a link to set its password.
- Upgrades change the resources of the same server and keep its egg variables. A product with another egg moves the
  server to that egg.
- "Go to server" opens the server's page in Pelican (`/server/<short id>`).
- Error messages show the HTTP status, the request, Pelican's reason and, for a missing permission, which one to add.
