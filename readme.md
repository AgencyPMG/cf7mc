# Contact Form 7 + MailChimp

Allows you to add checkboxes to your CF7 forms to add people to email lists.

Just put something like this in your CF7 area:

    [mailchimp my-list listid:YOURLISTID fname:WHERE_TO_GET_FNAME_MERGE_VAR]

There is a builder for these fields in Contact Form 7 admin area.

## Field Options

* `fname` The field name in which the data resides for the FNAME merge variable.
* `lname` The field name in which the data resides for the LNAME merge variable.
* `email` The field in which you want the plugin to look for the email to add.
Defaults to `your-email`.
* `list_id` The list to which you want the email to be added.  If not set, this
falls back to the default list id in the settings.  If no list ID is found, the
field will not display.
