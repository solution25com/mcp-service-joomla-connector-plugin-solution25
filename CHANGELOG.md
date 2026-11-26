# Changelog

## 1.0.0

First release.

- Pair a Joomla 6 site with Claude, ChatGPT, Gemini and other AI clients through the solution25 MCP gateway, using a one-time code.
- The admin chooses what the AI client may do: view, edit and create articles, and view the site menus.
- The AI client works through its own restricted user and API token, never an administrator, and can only use the routes its permissions allow.
- Permissions can be changed and the connection removed at any time from Components > AI connector.
- Connects, permission changes and disconnects are recorded in an audit log.
