# Query Model (v1)

## Shape

Top-level JSON:

```json
{
  "where": { "group": "ALL", "items": [ /* Rule|Group */ ], "not": false },
  "sort": { "field": "taken", "dir": "desc" },
  "limit": 200
}
```

`sort` and `limit` are optional. Default `limit` is `200`.

## Groups

```json
{
  "group": "ALL" | "ANY",
  "items": [Rule | Group],
  "not": false
}
```

`not` inverts the group when true.

## Rules

```json
{ "field": "tag", "op": "is", "value": "Alice" }
```

Supported rules:

- `tag`: `is`, `is_not` with a string value
- `taken`: `before`, `after`, `between` with YYYY-MM-DD date(s)
- `type`: `is`, `is_not` with `image|video|other`
- `path`: `contains`, `starts_with` with a string value

Date rules are interpreted in local time. `before` uses end-of-day, `after` uses start-of-day, and `between` is inclusive.

## Examples

All images after 2020-01-01:

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {"field": "type", "op": "is", "value": "image"},
      {"field": "taken", "op": "after", "value": "2020-01-01"}
    ]
  },
  "sort": {"field": "taken", "dir": "desc"},
  "limit": 200
}
```

Any of: tag is Alice, or path starts with a folder:

```json
{
  "where": {
    "group": "ANY",
    "items": [
      {"field": "tag", "op": "is", "value": "Alice"},
      {"field": "path", "op": "starts_with", "value": "/Users/bajanp/Pictures/Trips/"}
    ]
  },
  "sort": {"field": "path", "dir": "asc"}
}
```

Taken between 2018-01-01 and 2019-12-31, excluding tag "Private":

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {"field": "taken", "op": "between", "value": ["2018-01-01", "2019-12-31"]},
      {
        "group": "ALL",
        "items": [
          {"field": "tag", "op": "is", "value": "Private"}
        ],
        "not": true
      }
    ]
  }
}
```

Tags ["Sophie", "Gergely"] with Tag match=All and taken between:

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {
        "group": "ALL",
        "items": [
          { "field": "tag", "op": "is", "value": "Sophie" },
          { "field": "tag", "op": "is", "value": "Gergely" }
        ]
      },
      { "field": "taken", "op": "between", "value": ["2020-03-01", "2020-12-31"] }
    ]
  }
}
```

Tags ["Sophie", "Gergely"] with Tag match=Any and type=image:

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {
        "group": "ANY",
        "items": [
          { "field": "tag", "op": "is", "value": "Sophie" },
          { "field": "tag", "op": "is", "value": "Gergely" }
        ]
      },
      { "field": "type", "op": "is", "value": "image" }
    ]
  }
}
```
