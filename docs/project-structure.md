# Recommended Project Structure (Optional)

PHAPI is intentionally minimal and does not enforce a layout. If you want a tidy, scalable structure, use the following:

```
app/
  Controllers/
  Services/
  Middleware/
routes/
config/
var/
public/
```

## Notes
- `routes/` can be loaded via `PHAPI::loadApp()` or included directly.
- `config/` is optional; PHAPI reads defaults from `config/phapi.php` when present.
- `var/` is a good place for runtime logs, SQLite files, and job logs.
