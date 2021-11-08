# openprovider-whmcs-transfers

## Module Installation

1. Copy `/modules/addons/openprovider_transfers` folder to `<whmcs folder>/modules/addons/openprovider_transfers` folder.
2. add cron script to crontab: `*/10 * * * * <php> <whmcs folder>/modules/addons/openprovider_transfers/crons/cron.php`
3. Navigate to **Setup > Addon Modules** and activate Openprovider Transfers.
4. Click button **Configure**. Enter Openprovider credentials.
5. Click Save Changes.

## Module Usage

1. WHMCS Daily cron job loads scheduled domain transfers automatically.
2. If you want to make it yourself, go to the addon page: **Addons >> Openprovider Transfers**. Press the **Load scheduled transfers** button.
3. To remove all records you can click the **Remove List** button.
4. To remove all records with "FAI" Openprovider status, press the **Remove only FAI scheduled domains**.
5. You can update scheduled transfers by clicking **Update statuses** button.
6. To export all information click **Export CSV**.
7. Other buttons perform filtering.

If you want to make domain transferring to Openprovider silently, you need to activate this module
and trigger the Renew command in WHMCS.

If the transfer operation is successful, the module will change the domain registrar to Openprovider.
If Openprovider system makes the transfer then addon cron script will update the status of the scheduled transfer to ACT.
If something goes wrong, the addon script will return previous registrar to domain. 
