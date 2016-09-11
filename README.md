## Axxell Integration Prestashop (module)

This [Prestashop](https://www.prestashop.com) module shows a list of products that may be interested by shoppers. It uses the [Axxell API](https://axxell.cinaq.com) to provide intelligent recommendations.

Note: this is a minimal module that uses the Axxell API. We hope the Prestashop Community extends this in the future to make it more user friendly and add more features.

### Features

- Configure Axxell API information
- Configure number items to show (ideal count is 5)
- Footer banner
- Configure the type of recommendations to show: personalized or similar items
- Backfill with random products when needed

### Installation

- Obtain API credentials by registering an account at [Axxell](https://axxell.cinaq.com).
- On your prestashop server: `cd /var/www/html/modules/ && git clone https://github.com/xiwenc/axxell-integration-prestashop axxell-recommendations`.
- Configure and enable the module via Prestashop admin.
- Use the [Axxell Dashboard](https://axxell.cinaq.com/dashboard) to monitor the effectiveness of the system.

### Disclainer

- Use at your own risk.
- It is provided as-is.
- Non-confidential data is shared with Axxell
