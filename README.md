# BioCatalyst Link
This module creates a single API url that can be used by BioCatalyst to access reports from redcap projects.

The REDCap project must first be enabled to use this plugin and then any user with data export
rights will be able to view their reports.

### Security
Two security mechanisms are in place to try and control access to this API - IP filters AND a shared secret


### Example Syntax
TODO: The following parameters are valid in the body of the POST

    email_token: ##RANDOM## (this token is unique to this project)
    to:          A comma-separated list of valid email addresses (no names)
    from_name:   Jane Doe
    from_email:  Jane@doe.com
    cc:          (optional) comma-separated list of valid emails
    bcc:         (optional) comma-separated list of valid emails
    subject:     A Subject
    body:        A Message Body (<b>html</b> is okay!)
    record_id:   (optional) a record_id in the project - email will be logged to this record

The API will return a json object with either `result: true|false` or `error: error message`

### Misc
