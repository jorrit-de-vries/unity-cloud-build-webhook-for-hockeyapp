Unity Cloud Build Webhook for HockeyApp
=======================================

This package contains an implementation of a [Unity Cloud Build Webhook](https://build-api.cloud.unity3d.com/docs/1.0.0/index.html#operation-webhooks-intro).
The webhook listens for `ProjectBuildSuccess` events, downloads the
respective build artifacts for iOS and Android and uploads them to a
[HockeyApp](https://hockeyapp.net) environment.

Installation
------------

Download or clone the into a directory on your web server accesible over http.
https has not been tested. Configure the webhook on the Unity Cloud Build side
so it points to <your_domain/some_dir/ucb-webhook.php>. Create a copy of
`settings.php.template` and name that copy `settings.php`. Fill in the correct
details - ensure the values enclosed by '<>' are set. Make sure the webhook
endpoint works without errors.

Testing
-------

To be able to test you first need to obtain some test data. The easiest and
most reliable way is to set `CLOUD_BUILD_WEBHOOK_LOG_LEVEL` to 0, and have your
webhook called by Unity Cloud Build. If everything worked as expected, you can
find your build in HockeyApp.

If it didn't work as expected, for whatever reason, have a look in both the
server error log to look for possible errors in the php code, and at the
`ucb-log.log` file for output of the scrips.

In the log file you can find both the headers and the body of the event, which
can be used to test your setup. See `ucb-webhook-test.php.template` for a simple
posting mechanism. Don't forget to remove the test file once your done.

Notes
-----

When running PHP < 5.5, you might want to change the usage of `CURLFile` to
prepending the file path with `'@'`.

This package doesn't pretend to be the best php code, as writing php is not my
daily routine. Suggestions for improvement and security are more than welcome.

The scripts have been tested on a on a domain hosted by MediaTemple, running PHP 7.
