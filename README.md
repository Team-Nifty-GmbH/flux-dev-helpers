<p align="center"><a href="https://flux-erp.com" target="_blank"><img src="https://user-images.githubusercontent.com/40495041/160839207-0e1593e0-ff3d-4407-b9d2-d3513c366ab9.svg" width="400"></a></p>

## 1. Installation
Install the package via composer:
```bash
composer require team-nifty-gmbh/flux-office365
```

## 2. Adding an azure app
This is a step by step guide to create an azure app that can access the mailboxes of your organization.
The functionality of this package may be expanded in the future, so you may need to add more permissions later on.

1. Go to the [Azure Portal](https://portal.azure.com/)
2. Click on `App registrations` in the left sidebar
3. Click on `New registration`
4. Fill in the form and click on `Register`
5. Copy the `Application (client) ID` and `Directory (tenant) ID` to your `.env` file
6. Click on `Manage -> API Permissions` on the left sidebar
7. Click on `Add a permission`
8. Click on `APIs my organization uses`
9. Search for `Office 365 Exchange Online` and click on it
10. Click on `Application permissions`
11. Check the `IMAP.AccessAsApp` permission and click on `Add permissions`
12. Click on `Grant admin consent for <your-tenant-name>`
13. Click on `Certificates & secrets` in the left sidebar
14. Click on `New client secret`
15. Fill in the form and click on `Add`
16. Copy the `Value (client secret)` to your `.env` file
17. Go back to the overview and open `Enterprise applications`
18. On the overview page copy the `Object ID`

A full guide with screenshots can be found [here](https://vielhuber.de/en/blog/access-with-php-to-exchange-office-365/)

### 2.1 Adding the permissions to your mailboxes
You need the values from 2.5 and 2.18 for this step.

Open a powershell window and run the following commands:
```powershell
Install-Module -Name ExchangeOnlineManagement
Import-Module ExchangeOnlineManagement
Connect-ExchangeOnline -Organization <TENANTID>
New-ServicePrincipal -AppId <CLIENTID> -ServiceId <OBJECTID>
Add-MailboxPermission -Identity "<EMAIL>" -User <OBJECTID> -AccessRights FullAccess
```

This gives the flux package imap access to the mailbox.
Repeat the last command for every mailbox you want to access.

## 2. Configuration
Add the following to your `.env` file:
```dotenv
OFFICE365_TENANT_ID=<your-tenant-id>
OFFICE365_CLIENT_ID=<your-client-id>
OFFICE365_CLIENT_SECRET=<your-client-secret>
```
