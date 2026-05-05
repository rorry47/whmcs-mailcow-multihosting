# whmcs-mailcow-multihosting

A WHMCS provisioning module for [Mailcow](https://mailcow.email/) email hosting with **multi-domain support** — one order, multiple domains.

Based on [rorry47/mailcow_module_WHMCS](https://github.com/rorry47/mailcow_module_WHMCS).

<table style="width: 100%;">
  <tr>
    <td style="width: 33%; text-align: center;">
      <img src="https://github.com/rorry47/whmcs-mailcow-multihosting/blob/main/scrn1.jpg" alt="Overview tab" width="100%">
    </td>
    <td style="width: 33%; text-align: center;">
      <img src="https://github.com/rorry47/whmcs-mailcow-multihosting/blob/main/scrn2.jpg" alt="Statistics tab" width="100%">
    </td>
    <td style="width: 33%; text-align: center;">
      <img src="https://github.com/rorry47/whmcs-mailcow-multihosting/blob/main/scrn3.jpg" alt="Domains tab" width="100%">
    </td>
  </tr>
</table>

---

## Key difference from the base module

The original module provisions **one domain per order**. This module allows a single order to host **multiple domains** — each with its own mailboxes, aliases and DNS records. The number of domains per order is set per product in Module Settings.

---

## Features

- **Multi-domain** — clients can add and remove additional domains from the client area, up to the plan limit
- **Per-domain statistics** — storage usage, mailbox count and quota breakdown shown separately for each domain
- **Tariff plans** — mailbox limits, quotas and rate limits configured per product in WHMCS Module Settings; no config files
- **Suspend / Unsuspend** — disables or re-enables all client domains and the domain admin account
- **Terminate** — removes all mailboxes, aliases, domains and the domain admin in one action
- **Change Package** — applying a new plan updates limits on all client domains automatically
- **Change Password** — changes the domain admin password via WHMCS
- **DKIM management** — per-domain DKIM generation and display in the client area
- **Full DNS records** — MX, CNAME, SPF, DMARC, SRV and TLSA shown per domain
- **Localisation** — English, Russian, Ukrainian included; any WHMCS language supported

---

## How it works

The module uses a **super-admin API key** — no separate Mailcow administrator account is needed. All domains added by the client share one domain admin account and are stored in a WHMCS custom field.

```
Order placed → CreateAccount
    ├── Creates domain in Mailcow
    └── Creates domain admin account

Client adds domain (client area)
    ├── Checks domain does not already exist on server
    ├── Checks client has not reached domain limit
    ├── Creates domain in Mailcow
    ├── Assigns domain to client's admin account
    └── Saves domain list to WHMCS custom field

Order suspended → SuspendAccount
    ├── All client domains → active: 0
    └── Domain admin account → disabled

Order unsuspended → UnsuspendAccount
    ├── All client domains → active: 1
    └── Domain admin account → enabled

Order terminated → TerminateAccount
    ├── Deletes all mailboxes in all domains
    ├── Deletes all aliases in all domains
    ├── Deletes all domains
    └── Deletes domain admin account

Plan changed → ChangePackage
    └── Updates limits on all client domains
```

---

## Requirements

- WHMCS 7.x / 8.x / 9.x
- PHP 7.4+
- Mailcow with API enabled (super-admin read + write key)
- cURL PHP extension

---

## Installation

1. Copy the `modules/servers/mailcow/` folder to your WHMCS installation at the same path
2. In WHMCS admin: **Setup → Servers → Add New Server**
   - **Type:** MailCow
   - **Hostname:** your Mailcow hostname (e.g. `mail.example.com`)
   - **Access Hash:** your Mailcow super-admin API key
   - **Secure:** ✓
3. Create a product under **Setup → Products/Services**
   - **Module:** MailCow
   - **Server Group:** select the group containing the server from step 2
4. In the **Module Settings** tab configure the plan parameters (see table below)

> The API key must belong to a **super-admin** user. Domain-admin keys cannot create domains or users.

---

## Plan parameters (Module Settings)

| Field | Default | Description |
|---|---|---|
| Aliases Limit | `100` | Max aliases per domain |
| Mailboxes Limit | `10` | Max mailboxes per domain |
| Mailbox Quota (MB) | `1024` | Max quota per individual mailbox |
| Default Mailbox Quota (MB) | `1024` | Default quota when creating a mailbox |
| Total Domain Quota (MB) | `10240` | Combined storage quota per domain |
| Rate Limit Value | `10` | Messages per rate-limit frame |
| Rate Limit Frame | `s` | `s`=second `m`=minute `h`=hour `d`=day |
| Domains Limit | `1` | Max number of domains the client can have |

Each product can have different values. The quota applies **per domain** — a plan with 3 domains and 10 GB quota gives the client 30 GB total.

---

## Client area

The client portal shows three tabs:

**Overview**
- Open Mailcow Panel button
- Domain admin username
- Link to the Mailcow server

**Statistics**
- Per-domain breakdown: storage used/free/total, mailbox count, per-mailbox quota bars
- Summary row when multiple domains are present

**Domains**
- List of all domains with DNS records toggle and delete button
- DNS records per domain: MX, CNAME (autoconfig/autodiscover), SPF, DMARC, SRV, DKIM, TLSA
- Add domain form (shown when below the plan limit)
- Delete confirmation with mailbox count warning

---

## Localisation

Language files are in `modules/servers/mailcow/lang/`. WHMCS automatically loads the file matching the system language. Falls back to `english.php` if not found.

To add a language: copy `lang/english.php` to `lang/<language>.php` and translate the values.

---

## Support

- PayPall: `lyjex.lyjex@gmail.com`
- Bitcoin [BTC]: `1JK1og8cLFJ7CvRL6Ff5fEN8gzMDpNJFMm`
- Ethereum [ERC20]: `0x1f332bcca1b6b04824d18d31e52d1a7613113e7c`
- TetherUS [TRC20]: `TMXgowg4cQb1iLUSeADcvGHfb4F8HsSw1m`

---

## License

MIT
