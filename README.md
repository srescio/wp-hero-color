# WP Hero Color

WordPress plugin to compute deterministic hero colors from featured images and apply them as solid or gradient backgrounds.

## Status

Work in progress.

## Host Theme Integration Contract

The plugin stores the computed payload in post meta key `_sr_hero_bg`.
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

## Inspiration

The original idea is inspired by adaptive background extraction approaches such as `jquery.adaptive-backgrounds` and RGBaster.
