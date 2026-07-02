# Dynamic SQL report parameters

Configurable Reports web services can apply optional, bound values to SQL report queries. This lets a client retrieve a report's available parameters, submit values, and receive filtered data without interpolating user input into SQL.

## Web services

- `block_configurable_reports_get_reports` returns reports the current user is permitted to access. Each report includes its `id`, `name`, `summary`, `courseid`, `global` flag, type, and any dynamic parameters.
- `block_configurable_reports_get_report_data` accepts a report ID, an optional course ID, and optional parameter values. It returns the report data as a JSON string.

Reports with invalid dynamic parameters are omitted from `get_reports` and returned in its `warnings` collection. Unsupported report types or missing report classes are also returned as warnings.

## Placeholder syntax

Add placeholders to the SQL query where optional conditions should be appended:

```text
%%DYNAMIC_NAME:field:operator%%
```

`NAME` identifies the submitted parameter, `field` is the SQL field to filter, and `operator` is optional. If omitted, the operator defaults to `~`. Operators are case-insensitive.

For example:

```sql
SELECT u.id, u.username, u.firstname, u.lastname, u.email
FROM prefix_user u
WHERE u.deleted = 0
%%DYNAMIC_USERNAME:u.username:~%%
%%DYNAMIC_USER_ID:u.id:=%%
```

`NAME` is case-insensitive and may contain letters, numbers, underscores, and hyphens. Each name may appear only once in a report, regardless of case. `field` must be either a field name such as `id` or a single table-alias-qualified field such as `u.id`.

## Supported operators

| Operator | Meaning | Submitted value example | Generated condition |
| --- | --- | --- | --- |
| `~` | Contains match (default) | `smith` | `AND u.username LIKE '%smith%'` |
| `=` | Exact match | `42` | `AND u.id = 42` |
| `<` | Less than | `100` | `AND u.id < 100` |
| `>` | Greater than | `100` | `AND u.id > 100` |
| `<=` | Less than or equal to | `100` | `AND u.id <= 100` |
| `>=` | Greater than or equal to | `100` | `AND u.id >= 100` |
| `in` | Match any value in a list | `1,2,3` | `AND u.id IN (1, 2, 3)` |

The generated SQL shown above is illustrative. Values are always passed to Moodle through bound DML parameters, rather than embedded in the SQL text.

For `~`, `%` and `_` in the submitted value are treated as literal characters. For `in`, separate values with commas. Whitespace and surrounding single or double quotes are removed from each value; use `\,` for a literal comma within one value.

## Calling a report

First call `block_configurable_reports_get_reports` to discover the report ID and supported parameters. Then submit values to `block_configurable_reports_get_report_data`:

```json
{
  "reportid": 123,
  "courseid": 1,
  "parameters": [
    {
      "name": "USERNAME",
      "value": "smith"
    },
    {
      "name": "USER_ID",
      "value": "42"
    }
  ]
}
```

Parameter names are case-insensitive. A supplied name must contain only letters, numbers, underscores, and hyphens, and it may be supplied only once. Omitting a parameter, or supplying an empty value, removes its placeholder without adding a filter.

## Validation and safety

- Duplicate placeholder names prevent the report from being saved and are reported by `get_reports` as a warning for existing reports.
- A placeholder with unsupported syntax or an unsupported operator is rejected when the report is run; it is not silently executed as SQL.
- Only the field and operator written in the report SQL are used. Submitted values are bound query parameters.
- Dynamic conditions are appended with `AND`. Structure the surrounding SQL accordingly; placeholders do not add `WHERE`, `OR`, joins, ordering, or arbitrary SQL fragments.
