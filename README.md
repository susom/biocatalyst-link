# BioCatalyst Link
This module creates a single API url that can be used by BioCatalyst to access reports from redcap projects.

The REDCap project must first be enabled to use this plugin and then any user with data export
rights will be able to view their reports.

### Security
Two security mechanisms are in place to try and control access to this API - IP filters AND a shared secret


### Example Syntax
This module performs the following functions:
    1) POST request includes:
```json
{"token":"<token>", "request": "users", "user": "<sunetid>,<sunetid>"}
```

Return includes:

```json
[
  {
    "user":"<sunetid>",
    "projects": [
      {
        "project_id": "3049",
        "project_title": "Test Playground",
        "rights": {
          "data_export_tool": "1",
           "reports": "1"
        }
      },
      {
        "project_id": "xxxx",
        "project_title": "Project 2",
        "rights": {
          "data_export_tool": "1",
          "reports": "1"
        }
    ]
  },
  {
    "user2": "sunetid2",
    "projects": [
      ...
    ]
  }
]
```

    2) POST request includes:
            {"token":"<token>", "request": "reports", "user": "<sunetid>", "project_id" : "<project_id>"}
       Return includes {"project_id":"<pid1>",
                        "reports":{"report_id":"<report_id>", "title":"<title>"},
                                  {...}]}

    3) POST request includes:
            {"token":"<token>", "request": "reports", "user": "<sunetid>", "project_id" : "<project_id>", "report_id":"<report_id>"}
       Return includes [{"field1":"<value1>",
                         "field2":"<value2>",
                                ...         },
                        {"field1":"<value1>",
                         "field2":"<value2>",
                                ...         }]


### Misc
Contacts for BioCatalyst are:
    Jessiely Juachon <jessiely@stanford.edu>
    Rohit K. Gupta <rogupta@stanford.edu>
