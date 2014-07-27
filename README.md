# forkcms-moblog

Module to [moblog](http://www.urbandictionary.com/define.php?term=moblog) with your mobile (or other email client) to your blog.

## installation

Upload \Backend\Modules\Moblog to your webserver. Change your parameters.yml according to provided parameters.yml.dist.

Add a crontab with ```crontab -l``` on your server:
```
# fetch new moblogs
*/5 * * * * /usr/bin/wget -O - --quiet --timeout=1440 "http://www.jezek.ch/src/Backend/Cronjob.php?module=Moblog&action=FetchAndProcessEmails"
```

## Notes

Initial version which works. Only tested with IMAPs.

## Known issues

- installer does nothing



