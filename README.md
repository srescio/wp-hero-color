# WP Hero Color

WordPress plugin to compute deterministic hero colors from featured images and apply them as solid or gradient backgrounds.

<img src="./assets/banner-772x250.png" alt="WP Hero Color banner" width="386" />

## Preview

Editor panel preview samples:

<img src="./assets/screenshots/preview-conic.png" alt="Conic preview" width="280" />
<img src="./assets/screenshots/preview-solid.png" alt="Solid preview" width="280" />
<img src="./assets/screenshots/preview-linear.png" alt="Linear preview" width="280" />

## Modes

- `solid` (main dominant color)
- `linear` (edge-averaged gradient with selectable direction)
- `conic` (ambilight-style conic gradient using 8 edge colors)

## Data model

The plugin stores the computed payload in post meta key `_sr_hero_bg`:

- `main`: dominant color
- `edges`: 8 edge colors (corners + midpoints)
- `mode`: `solid|linear|conic`
- `linear_dir`: `vertical|horizontal|diag_tl_br|diag_tr_bl`

## Admin settings

In **wp-admin**, open **Settings → Hero Color** (capability: `manage_options`). From there you can:

- Run **bulk recompute** on the server (same logic as `wp hero-color recompute_all`), with scope and optional mode overrides.
- Copy **REST** and **WP-CLI over SSH** examples for automation (MCP and remote hosts use these; the browser cannot open SSH itself).

## Host Theme Integration Contract

To apply the result in frontend markup, host themes should add plugin-provided attributes on the hero wrapper element (typically `.post-thumbnail`):

- `data-sr-hero-computed`
- `data-sr-hero-mode`
- `data-sr-hero-dir`
- inline `style` containing `--sr-hero-main`, `--sr-hero-bg`, and background declarations

### Optional helper snippet (theme side)

```php
<?php
if ( function_exists( 'wp_hero_color_get_attributes' ) ) {
    $attrs = wp_hero_color_get_attributes( get_the_ID() );
    foreach ( $attrs as $key => $value ) {
        printf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
    }
}
?>
```

The plugin stylesheet reads these attributes/variables and applies consistent placeholders and gradients on both single and listing contexts.

## REST API usage

Compute/recompute and save payload:

```bash
curl -X POST "https://example.com/wp-json/sr-hero-color/v1/compute" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <wp_rest_nonce>" \
  --data '{"post_id":123,"attachment_id":456,"mode":"conic","linear_dir":"vertical"}'
```

Read computed payload for a post:

```bash
curl "https://example.com/wp-json/sr-hero-color/v1/post/123"
```

## WP-CLI usage

Single post recompute:

```bash
wp hero-color recompute --post_id=123 --mode=conic --linear_dir=vertical
```

Bulk recompute by post type:

```bash
wp hero-color recompute_all --post_type=post --mode=linear --linear_dir=horizontal
```

Bulk recompute all supported types:

```bash
wp hero-color recompute_all --mode=solid
```

### Run WP-CLI over SSH

Example direct SSH usage:

```bash
ssh user@example.com "cd /path/to/wordpress && wp hero-color recompute_all --post_type=post --mode=conic"
```

If your project includes a helper script such as `scripts/ssh-wp-prod.sh`, you can run:

```bash
bash scripts/ssh-wp-prod.sh wp hero-color recompute_all --post_type=post --mode=conic
```

## Inspiration

The original idea is inspired by adaptive background extraction approaches such as `jquery.adaptive-backgrounds` and RGBaster.
