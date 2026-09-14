# Boat URL templates

The **SEO > Boat base slug** setting accepts a comma-separated map of
language-specific path templates. Each template may contain these placeholders:

| Placeholder | Value source | Example |
| --- | --- | --- |
| `{{destination}}` | Commercial destination selected from the API `destinations` catalogue | `mallorca` |
| `{{boat_type}}` | Localized Maradigma boat type catalogue | `yate` |

The aliases `{{destination_slug}}` and `{{boat_type_slug}}` are also accepted.

## Example

```text
es:{{destination}}/alquiler-{{boat_type}},en:{{destination}}/boat-rental-{{boat_type}},ca:{{destination}}/lloguer-{{boat_type}}
```

This can generate URLs such as:

```text
/mallorca/alquiler-yate/nombre-del-barco/
/ibiza/alquiler-lancha/nombre-del-barco/
```

Run the boat-page synchronization after changing this setting. The sync loads
the complete `destinations` catalogue, refreshes the localized boat type
catalogue and stores the resolved routing data in each boat page payload.

The API may return a locality before its island in the legacy singular
`destination` field. The plugin therefore uses the plural catalogue and prefers
an island such as Mallorca or Ibiza over its cities, marinas and ports. If the
plural catalogue is unavailable, the singular field remains the compatibility
fallback.

## Missing values and static ancestor pages

Dynamic rewrite rules require every configured segment plus the final boat
slug. They therefore do not intercept static destination or boat-type landing
pages such as `/alquiler-de-barcos/ibiza/` or
`/alquiler-de-barcos/ibiza/lancha/`.

Destination and boat type are optional API data. When either value is missing,
the boat uses the separate, unambiguous fallback route:

```text
/boats/nombre-del-barco/
```

To obtain destination-prefixed URLs, configure the boat's real base port and
the base port-to-destination mapping in Maradigma so that `destinations`
contains a usable geographical destination.
