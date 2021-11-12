# openprovider-whmcs-transfers

## Module Installation

1. Copy `/modules/addons/openprovider_transfers` folder to `<whmcs folder>/modules/addons/openprovider_transfers` folder.
2. add cron script to crontab: `*/10 * * * * <php> <whmcs folder>/modules/addons/openprovider_transfers/crons/cron.php`
3. Navigate to **Setup > Addon Modules** and activate Openprovider Transfers.
4. Click button **Configure**. Enter Openprovider credentials.
5. Click Save Changes.

## Module Usage

1. WHMCS Daily cron job loads scheduled domain transfers automatically. The domains which are scheduled for transfer in Openprovider AND exist in your WHMCS instance will appear in the list of domains in the addon module.
2. To manually load transfers, go to the addon page: **Addons >> Openprovider Transfers**. Press the **Load scheduled transfers** button.
3. To remove all records from the addon module overview, you can click the **Remove List** button. Note that this will not remove the scheduled transfers from Openprovider.
4. To remove all records with "FAI" Openprovider status, press the **Remove only FAI scheduled domains**.
5. You can update scheduled transfers by clicking **Update statuses** button.
6. To export all information click **Export CSV**.
7. Other buttons perform filtering.

If a domain is scheduled for transfer in Openprovider and was loaded by the trasnfer module, then it will _not renew_ at the current registrar when WHMCS sends the renewal command. Instead the scheduled transfer request will be triggered in Openprovider, and the domain object in WHMCS will have the registrar setting changed to Openprovider.

If the transfer is successfull, then addon cron script will update the status of the scheduled transfer to ACT.
If the trasnfer fails for any reason, the addon module will create a TODO item for the WHMCS administrator to check.

