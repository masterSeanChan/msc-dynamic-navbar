<?php
/**
 * Plugin Name:  MSC Dynamic Navbar
 * Plugin URI:   https://www.masterseanchan.com
 * Description:  Dynamic navigation bar that auto-syncs with WordPress Menus. Go to Appearance → Menus and assign a menu to the "MSC Navbar" location.
 * Version:      1.0.0
 * Author:       Master Sean Chan
 * License:      GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class MSC_Dynamic_Navbar {

    public static function boot() {
        add_action( 'after_setup_theme', [ __CLASS__, 'register_menu' ] );
        if ( ! is_admin() ) {
            add_action( 'wp_head',   [ __CLASS__, 'print_styles' ], 999 );
            add_action( 'wp_footer', [ __CLASS__, 'print_navbar' ], 5 );
        }
    }

    /* ─── Register menu location ──────────────────────────── */

    public static function register_menu() {
        register_nav_menus( [ 'msc_navbar' => __( 'MSC Navbar' ) ] );
    }

    /* ─── Build a tree from flat WP menu items ────────────── */

    private static function is_current_url( $url ) {
        if ( ! $url || $url === '#' ) return false;
        $current = trailingslashit( strtok( $_SERVER['REQUEST_URI'], '?' ) );
        $target  = trailingslashit( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
        return $current === $target;
    }

    private static function get_menu_tree() {
        $locations = get_nav_menu_locations();
        if ( empty( $locations['msc_navbar'] ) ) return null;

        $menu = wp_get_nav_menu_object( $locations['msc_navbar'] );
        if ( ! $menu ) return null;

        $flat = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
        if ( ! $flat ) return null;

        usort( $flat, function ( $a, $b ) {
            return $a->menu_order - $b->menu_order;
        } );

        $map = [];
        foreach ( $flat as $item ) {
            $map[ $item->ID ] = (object) [
                'id'       => $item->ID,
                'title'    => $item->title,
                'url'      => $item->url,
                'target'   => $item->target,
                'classes'  => array_filter( (array) $item->classes ),
                'current'  => self::is_current_url( $item->url ),
                'parent'   => (int) $item->menu_item_parent,
                'children' => [],
            ];
        }

        $tree = [];
        foreach ( $map as $node ) {
            if ( $node->parent && isset( $map[ $node->parent ] ) ) {
                $map[ $node->parent ]->children[] = $node;
            } else {
                $tree[] = $node;
            }
        }

        /* Mark parent as current-ancestor if any child is current */
        foreach ( $tree as $node ) {
            foreach ( $node->children as $child ) {
                if ( $child->current ) {
                    $node->current_ancestor = true;
                    break;
                }
            }
        }

        return $tree;
    }

    /* ─── Small helpers ───────────────────────────────────── */

    private static function link_attrs( $item ) {
        $attrs = '';
        if ( $item->target ) {
            $attrs .= ' target="' . esc_attr( $item->target ) . '" rel="noopener noreferrer"';
        }
        $classes = implode( ' ', $item->classes );
        if ( $classes ) {
            $attrs .= ' class="' . esc_attr( $classes ) . '"';
        }
        return $attrs;
    }

    /* ─── Desktop menu items ──────────────────────────────── */

    private static function render_desktop_items( $tree ) {
        $out = '';
        foreach ( $tree as $item ) {
            $is_active   = ! empty( $item->current );
            $is_ancestor = ! empty( $item->current_ancestor );

            if ( ! empty( $item->children ) ) {
                $is_placeholder = in_array( $item->url, [ '#', '' ], true );
                $href    = $is_placeholder ? '#' : esc_url( $item->url );
                $onclick = $is_placeholder ? ' onclick="return false;" style="cursor:default;"' : '';
                $li_cls  = 'msc-has-dropdown' . ( $is_ancestor ? ' msc-current-ancestor' : '' );

                $out .= '<li class="' . $li_cls . '" role="none">';
                $out .= '<a href="' . $href . '" role="menuitem" aria-haspopup="true" aria-expanded="false"' . $onclick . self::link_attrs( $item ) . '>' . esc_html( $item->title ) . '</a>';
                $out .= '<ul class="msc-dropdown" role="menu">';
                foreach ( $item->children as $child ) {
                    $child_active = ! empty( $child->current );
                    $child_li_cls = $child_active ? ' class="msc-current"' : '';
                    $aria_cur     = $child_active ? ' aria-current="page"' : '';
                    $out .= '<li' . $child_li_cls . '><a href="' . esc_url( $child->url ) . '" role="menuitem"' . $aria_cur . self::link_attrs( $child ) . '>' . esc_html( $child->title ) . '</a></li>';
                }
                $out .= '</ul></li>';
            } else {
                $li_cls   = $is_active ? ' class="msc-current"' : '';
                $aria_cur = $is_active ? ' aria-current="page"' : '';
                $out .= '<li' . $li_cls . ' role="none"><a href="' . esc_url( $item->url ) . '" role="menuitem"' . $aria_cur . self::link_attrs( $item ) . '>' . esc_html( $item->title ) . '</a></li>';
            }
        }
        return $out;
    }

    /* ─── Mobile menu items ───────────────────────────────── */

    private static function render_mobile_items( $tree ) {
        static $sub_idx = 0;
        $out = '';
        $arrow = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">'
               . '<path d="M2 4.5L7 9.5L12 4.5" stroke="#B26E3C" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
               . '</svg>';

        foreach ( $tree as $item ) {
            $is_active   = ! empty( $item->current );
            $is_ancestor = ! empty( $item->current_ancestor );

            if ( ! empty( $item->children ) ) {
                $sub_idx++;
                $sub_id = 'msc-mob-sub-' . $sub_idx;
                $li_cls = $is_ancestor ? ' class="msc-current-ancestor"' : '';

                $out .= '<li' . $li_cls . '>';
                $out .= '<div class="msc-mob-parent-row">';
                $out .= '<button class="msc-mob-parent" aria-expanded="false" aria-controls="' . $sub_id . '">' . esc_html( $item->title ) . '</button>';
                $out .= '<span class="msc-mob-arrow">' . $arrow . '</span>';
                $out .= '</div>';
                $out .= '<ul class="msc-mob-sub" id="' . $sub_id . '">';
                foreach ( $item->children as $child ) {
                    $child_active = ! empty( $child->current );
                    $child_cls    = $child_active ? ' class="msc-current"' : '';
                    $aria_cur     = $child_active ? ' aria-current="page"' : '';
                    $out .= '<li' . $child_cls . '><a href="' . esc_url( $child->url ) . '"' . $aria_cur . self::link_attrs( $child ) . '>' . esc_html( $child->title ) . '</a></li>';
                }
                $out .= '</ul></li>';
            } else {
                $li_cls   = $is_active ? ' class="msc-current"' : '';
                $aria_cur = $is_active ? ' aria-current="page"' : '';
                $out .= '<li' . $li_cls . '><a href="' . esc_url( $item->url ) . '"' . $aria_cur . self::link_attrs( $item ) . '>' . esc_html( $item->title ) . '</a></li>';
            }
        }
        return $out;
    }

    /* ─── CSS (identical to original) ─────────────────────── */

    public static function print_styles() {
        if ( is_admin() ) return;
?>
<style id="msc-navbar-styles">
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Source+Sans+Pro:wght@300;400;600&family=Source+Serif+Pro:wght@400;600&display=swap');

#msc-navbar,#msc-mobile-menu,#msc-panel-overlay{
  --msc-dark-brown:#3B2F26;--msc-brown-mid:#5C4A3A;--msc-gold:#D6AD60;
  --msc-copper:#B26E3C;--msc-taupe:#A8957A;--msc-cream:#EFE4C8;
  --msc-cream-light:#FAF6EE;--msc-cream-border:#DDD0B0;
  --msc-font-heading:'Playfair Display',Georgia,serif;
  --msc-font-sub:'Source Sans Pro',sans-serif;
  --msc-font-body:'Source Serif Pro',Georgia,serif;
  --msc-nav-height:82px;
  --msc-ease:0.28s cubic-bezier(0.4,0,0.2,1);
}

#main-header,#et-top-navigation,
.et_header_style_centered #main-header,
.et_header_style_left #main-header{display:none !important;}
body.et_fixed_nav{padding-top:0 !important;}
#page-container,.et_pb_section:first-of-type,
#et_builder_outer_content{padding-top:var(--msc-nav-height) !important;}

#msc-navbar{
  position:fixed !important;top:0 !important;left:0 !important;right:0 !important;
  width:100% !important;z-index:999999 !important;
  height:var(--msc-nav-height) !important;
  background:rgba(250,246,238,0.95) !important;
  backdrop-filter:blur(16px) !important;-webkit-backdrop-filter:blur(16px) !important;
  box-shadow:0 1px 0 rgba(168,149,122,0.3),0 4px 28px rgba(59,47,38,0.06) !important;
  border-bottom:none !important;
  transition:height var(--msc-ease),background var(--msc-ease),box-shadow var(--msc-ease) !important;
  display:flex !important;align-items:center !important;
  box-sizing:border-box !important;margin:0 !important;padding:0 !important;
}
#msc-navbar::after{
  content:'' !important;position:absolute !important;
  bottom:0 !important;left:8% !important;right:8% !important;height:1px !important;
  background:linear-gradient(90deg,transparent,rgba(214,173,96,0.5),transparent) !important;
  pointer-events:none !important;
}
#msc-navbar.msc-scrolled{
  height:66px !important;background:rgba(250,246,238,0.99) !important;
  box-shadow:0 1px 0 rgba(168,149,122,0.35),0 8px 36px rgba(59,47,38,0.09) !important;
}

#msc-navbar .msc-nav-container{
  width:100% !important;max-width:1300px !important;
  margin:0 auto !important;padding:0 40px !important;
  display:flex !important;align-items:center !important;
  justify-content:space-between !important;gap:16px !important;
  box-sizing:border-box !important;
}

#msc-navbar .msc-logo{
  display:flex !important;align-items:center !important;
  text-decoration:none !important;flex-shrink:0 !important;
  border:none !important;background:none !important;
  line-height:0 !important;
}
#msc-navbar .msc-logo-img{
  display:block !important;width:auto !important;
  max-width:160px !important;height:52px !important;
  object-fit:contain !important;object-position:left center !important;
  transition:opacity var(--msc-ease),transform var(--msc-ease) !important;
}
#msc-navbar .msc-logo:hover .msc-logo-img{opacity:0.78 !important;transform:translateY(-1px) !important;}
#msc-navbar.msc-scrolled .msc-logo-img{height:42px !important;}

#msc-navbar .msc-nav-links{
  display:flex !important;align-items:center !important;
  list-style:none !important;gap:0 !important;
  margin:0 !important;padding:0 !important;
  flex:1 !important;justify-content:center !important;
}
#msc-navbar .msc-nav-links li{
  margin:0 !important;padding:0 !important;
  list-style:none !important;position:relative !important;
}
#msc-navbar .msc-nav-links li a{
  display:inline-flex !important;align-items:center !important;gap:5px !important;
  font-family:var(--msc-font-heading) !important;
  font-size:0.95rem !important;font-weight:400 !important;font-style:italic !important;
  color:var(--msc-brown-mid) !important;
  text-decoration:none !important;letter-spacing:0.03em !important;text-transform:none !important;
  padding:7px 11px !important;border-radius:3px !important;white-space:nowrap !important;
  background-image:linear-gradient(var(--msc-copper),var(--msc-copper)) !important;
  background-size:0% 1px !important;background-repeat:no-repeat !important;
  background-position:center bottom 1px !important;
  transition:color var(--msc-ease),background-size var(--msc-ease),background-color var(--msc-ease) !important;
  border:none !important;box-shadow:none !important;
}
#msc-navbar .msc-nav-links li a:hover{
  color:var(--msc-copper) !important;
  background-size:70% 1px !important;
  background-color:rgba(178,110,60,0.05) !important;
}

#msc-navbar .msc-nav-links li.msc-has-dropdown>a::before{
  content:'' !important;position:absolute !important;
  bottom:-6px !important;left:0 !important;right:0 !important;
  height:8px !important;background:transparent !important;
}
#msc-navbar .msc-nav-links li.msc-has-dropdown>a::after{
  content:'' !important;display:inline-block !important;
  width:4px !important;height:4px !important;
  border-right:1.2px solid currentColor !important;
  border-bottom:1.2px solid currentColor !important;
  transform:rotate(45deg) translateY(-2px) !important;
  flex-shrink:0 !important;opacity:0.7 !important;
  transition:transform var(--msc-ease),opacity var(--msc-ease) !important;
}
#msc-navbar .msc-nav-links li.msc-has-dropdown:hover>a::after{
  transform:rotate(-135deg) translateY(2px) !important;opacity:1 !important;
}

#msc-navbar .msc-dropdown{
  position:absolute !important;top:100% !important;
  left:50% !important;transform:translateX(-50%) translateY(4px) !important;
  background:var(--msc-cream-light) !important;
  border:1px solid var(--msc-cream-border) !important;
  border-top:2px solid var(--msc-gold) !important;
  min-width:240px !important;list-style:none !important;
  padding:6px 0 8px !important;margin:0 !important;
  border-radius:0 0 8px 8px !important;
  opacity:0 !important;pointer-events:none !important;
  transition:opacity var(--msc-ease),transform var(--msc-ease) !important;
  box-shadow:0 16px 48px rgba(59,47,38,0.1),0 4px 12px rgba(59,47,38,0.06) !important;
  z-index:1000000 !important;
}
#msc-navbar .msc-nav-links li.msc-has-dropdown:hover .msc-dropdown{
  opacity:1 !important;pointer-events:all !important;
  transform:translateX(-50%) translateY(0) !important;
}
#msc-navbar .msc-dropdown li{list-style:none !important;margin:0 !important;padding:0 !important;}
#msc-navbar .msc-dropdown li+li{border-top:1px solid rgba(168,149,122,0.15) !important;}
#msc-navbar .msc-dropdown li a{
  display:block !important;
  font-family:var(--msc-font-body) !important;font-size:0.9rem !important;
  font-weight:400 !important;font-style:normal !important;
  color:var(--msc-dark-brown) !important;text-decoration:none !important;
  letter-spacing:0.01em !important;padding:10px 22px !important;
  white-space:nowrap !important;background-image:none !important;
  transition:background var(--msc-ease),color var(--msc-ease),padding-left var(--msc-ease) !important;
}
#msc-navbar .msc-dropdown li a:hover{
  background:rgba(214,173,96,0.14) !important;
  color:var(--msc-copper) !important;padding-left:30px !important;
}

#msc-navbar .msc-cta{
  display:inline-flex !important;align-items:center !important;
  font-family:var(--msc-font-sub) !important;font-size:0.75rem !important;
  font-weight:600 !important;letter-spacing:0.15em !important;text-transform:uppercase !important;
  color:var(--msc-cream-light) !important;background:var(--msc-dark-brown) !important;
  text-decoration:none !important;padding:11px 22px !important;
  border-radius:40px !important;white-space:nowrap !important;flex-shrink:0 !important;
  position:relative !important;overflow:hidden !important;border:none !important;
  box-shadow:0 2px 14px rgba(59,47,38,0.2) !important;
  transition:color var(--msc-ease),transform var(--msc-ease),box-shadow var(--msc-ease) !important;
}
#msc-navbar .msc-cta::before{
  content:'' !important;position:absolute !important;inset:0 !important;
  background:var(--msc-copper) !important;transform:translateX(-101%) !important;
  border-radius:40px !important;transition:transform var(--msc-ease) !important;
}
#msc-navbar .msc-cta span{position:relative !important;z-index:1 !important;}
#msc-navbar .msc-cta:hover{
  color:var(--msc-cream-light) !important;
  box-shadow:0 6px 28px rgba(178,110,60,0.32) !important;transform:translateY(-1px) !important;
}
#msc-navbar .msc-cta:hover::before{transform:translateX(0) !important;}

/* ── Nav actions (IG + search icons) ── */
#msc-navbar .msc-nav-actions{
  display:flex !important;align-items:center !important;gap:4px !important;
  flex-shrink:0 !important;
}
#msc-navbar .msc-icon-btn{
  display:flex !important;align-items:center !important;justify-content:center !important;
  width:34px !important;height:34px !important;
  color:var(--msc-brown-mid) !important;border-radius:50% !important;
  transition:color var(--msc-ease),background var(--msc-ease) !important;
  text-decoration:none !important;border:none !important;background:none !important;
  cursor:pointer !important;padding:0 !important;box-shadow:none !important;
}
#msc-navbar .msc-icon-btn:hover{
  color:var(--msc-copper) !important;
  background:rgba(178,110,60,0.08) !important;
}
#msc-navbar .msc-icon-btn svg{
  width:18px !important;height:18px !important;flex-shrink:0 !important;
}

/* ── Search bar ── */
#msc-navbar .msc-search-bar{
  position:absolute !important;top:100% !important;left:0 !important;right:0 !important;
  background:var(--msc-cream-light) !important;
  border-top:1px solid var(--msc-cream-border) !important;
  border-bottom:2px solid var(--msc-gold) !important;
  box-shadow:0 12px 36px rgba(59,47,38,0.1) !important;
  padding:16px 40px !important;
  display:none !important;z-index:1 !important;
}
#msc-navbar .msc-search-bar.msc-open{display:block !important;}
#msc-navbar .msc-search-form{
  max-width:600px !important;margin:0 auto !important;
  display:flex !important;gap:10px !important;
}
#msc-navbar .msc-search-input{
  flex:1 !important;
  font-family:var(--msc-font-body) !important;font-size:1rem !important;
  color:var(--msc-dark-brown) !important;background:#fff !important;
  border:1px solid var(--msc-cream-border) !important;
  border-radius:6px !important;padding:10px 16px !important;
  outline:none !important;box-shadow:none !important;
  -webkit-appearance:none !important;
}
#msc-navbar .msc-search-input:focus{
  border-color:var(--msc-copper) !important;
  box-shadow:0 0 0 3px rgba(178,110,60,0.12) !important;
}
#msc-navbar .msc-search-submit{
  font-family:var(--msc-font-sub) !important;font-size:0.75rem !important;
  font-weight:600 !important;letter-spacing:0.1em !important;text-transform:uppercase !important;
  color:var(--msc-cream-light) !important;background:var(--msc-dark-brown) !important;
  border:none !important;border-radius:6px !important;
  padding:10px 22px !important;cursor:pointer !important;
  transition:background var(--msc-ease) !important;
}
#msc-navbar .msc-search-submit:hover{background:var(--msc-copper) !important;}

/* ── Mobile menu social ── */
#msc-mobile-menu .msc-mob-social{
  display:flex !important;align-items:center !important;gap:12px !important;
  margin-top:20px !important;padding-top:16px !important;
  border-top:1px solid rgba(168,149,122,0.2) !important;
}
#msc-mobile-menu .msc-mob-social a{
  display:flex !important;align-items:center !important;gap:8px !important;
  font-family:var(--msc-font-body) !important;font-size:0.9rem !important;
  color:var(--msc-brown-mid) !important;text-decoration:none !important;
  border:none !important;padding:0 !important;
}
#msc-mobile-menu .msc-mob-social a:hover{color:var(--msc-copper) !important;}
#msc-mobile-menu .msc-mob-social svg{width:18px !important;height:18px !important;}

#msc-navbar .msc-hamburger{
  display:none !important;flex-direction:column !important;
  justify-content:center !important;gap:5px !important;
  width:40px !important;height:40px !important;
  background:transparent !important;border:1px solid rgba(92,74,58,0.22) !important;
  border-radius:4px !important;cursor:pointer !important;padding:8px !important;
  flex-shrink:0 !important;box-shadow:none !important;
  transition:border-color var(--msc-ease),background var(--msc-ease) !important;
}
#msc-navbar .msc-hamburger:hover{
  background:rgba(92,74,58,0.06) !important;border-color:var(--msc-copper) !important;
}
#msc-navbar .msc-hamburger span{
  display:block !important;width:100% !important;height:1.5px !important;
  background:var(--msc-dark-brown) !important;border-radius:2px !important;
  transition:transform var(--msc-ease),opacity var(--msc-ease) !important;
  transform-origin:center !important;
}
#msc-navbar .msc-hamburger.msc-open span:nth-child(1){transform:translateY(6.5px) rotate(45deg) !important;}
#msc-navbar .msc-hamburger.msc-open span:nth-child(2){opacity:0 !important;transform:scaleX(0) !important;}
#msc-navbar .msc-hamburger.msc-open span:nth-child(3){transform:translateY(-6.5px) rotate(-45deg) !important;}

#msc-panel-overlay{
  position:fixed !important;inset:0 !important;
  background:rgba(59,47,38,0.35) !important;z-index:999997 !important;
  opacity:0 !important;visibility:hidden !important;
  backdrop-filter:blur(2px) !important;-webkit-backdrop-filter:blur(2px) !important;
  transition:opacity 0.32s cubic-bezier(0.4,0,0.2,1),visibility 0.32s !important;
}
#msc-panel-overlay.msc-panel-open{opacity:1 !important;visibility:visible !important;}

#msc-mobile-menu{
  position:fixed !important;top:0 !important;left:0 !important;
  width:300px !important;max-width:85vw !important;height:100vh !important;
  background:var(--msc-cream-light) !important;
  border-right:1px solid var(--msc-cream-border) !important;
  box-shadow:4px 0 32px rgba(59,47,38,0.14) !important;
  transform:translateX(-100%) !important;
  z-index:999998 !important;
  box-sizing:border-box !important;overflow-y:auto !important;overflow-x:hidden !important;
}
#msc-mobile-menu.msc-panel-open{transform:translateX(0) !important;}

#msc-mobile-menu .msc-panel-header{
  display:flex !important;align-items:center !important;
  justify-content:space-between !important;padding:20px 24px 16px !important;
  border-bottom:1px solid var(--msc-cream-border) !important;
  position:sticky !important;top:0 !important;
  background:var(--msc-cream-light) !important;z-index:1 !important;
}
#msc-mobile-menu .msc-panel-logo{
  font-family:var(--msc-font-heading) !important;font-size:1.05rem !important;
  font-style:italic !important;color:var(--msc-dark-brown) !important;opacity:0.7 !important;
}
#msc-mobile-menu .msc-panel-close{
  width:32px !important;height:32px !important;
  display:flex !important;align-items:center !important;justify-content:center !important;
  background:none !important;border:1px solid rgba(92,74,58,0.2) !important;
  border-radius:50% !important;cursor:pointer !important;
  color:var(--msc-dark-brown) !important;font-size:1rem !important;line-height:1 !important;
  flex-shrink:0 !important;box-shadow:none !important;
}
#msc-mobile-menu .msc-panel-close:hover{
  background:rgba(92,74,58,0.08) !important;
  border-color:var(--msc-copper) !important;color:var(--msc-copper) !important;
}

#msc-mobile-menu .msc-mobile-inner{padding:16px 24px 40px !important;box-sizing:border-box !important;}
#msc-mobile-menu ul{list-style:none !important;margin:0 !important;padding:0 !important;}
#msc-mobile-menu ul li{list-style:none !important;margin:0 !important;padding:0 !important;}
#msc-mobile-menu ul li a,#msc-mobile-menu .msc-mob-parent{
  display:block !important;
  font-family:var(--msc-font-heading) !important;font-size:1.15rem !important;
  font-weight:400 !important;font-style:italic !important;
  color:var(--msc-dark-brown) !important;text-decoration:none !important;
  letter-spacing:0.02em !important;padding:13px 0 !important;
  border-bottom:1px solid rgba(168,149,122,0.2) !important;
  width:100% !important;text-align:left !important;background:none !important;
  border-left:none !important;border-right:none !important;border-top:none !important;
  cursor:pointer !important;box-shadow:none !important;
  box-sizing:border-box !important;
}
#msc-mobile-menu ul>li:first-child>a,
#msc-mobile-menu ul>li:first-child>.msc-mob-parent-row{
  border-top:1px solid rgba(168,149,122,0.2) !important;
}
#msc-mobile-menu ul li a:hover{color:var(--msc-copper) !important;}
#msc-mobile-menu .msc-mob-parent:hover{color:var(--msc-copper) !important;}

#msc-mobile-menu .msc-mob-parent-row{
  display:flex !important;align-items:center !important;
  justify-content:space-between !important;
  border-bottom:1px solid rgba(168,149,122,0.2) !important;
}
#msc-mobile-menu .msc-mob-parent-row .msc-mob-parent{border-bottom:none !important;flex:1 !important;}

#msc-mobile-menu .msc-mob-arrow{
  width:28px !important;height:28px !important;
  display:flex !important;align-items:center !important;justify-content:center !important;
  flex-shrink:0 !important;
}
#msc-mobile-menu .msc-mob-arrow.msc-open svg{transform:rotate(180deg);}

#msc-mobile-menu .msc-mob-sub{
  list-style:none !important;max-height:0 !important;overflow:hidden !important;
  background:rgba(168,149,122,0.07) !important;
  border-radius:0 0 6px 6px !important;margin:0 !important;padding:0 !important;
}
#msc-mobile-menu .msc-mob-sub.msc-open{max-height:600px !important;}
#msc-mobile-menu .msc-mob-sub li a{
  font-family:var(--msc-font-body) !important;font-size:0.92rem !important;
  font-weight:400 !important;font-style:normal !important;
  color:var(--msc-brown-mid) !important;padding:9px 0 9px 20px !important;
  letter-spacing:0.01em !important;border-top:none !important;
  border-bottom:1px solid rgba(168,149,122,0.12) !important;
}
#msc-mobile-menu .msc-mob-sub li a:hover{color:var(--msc-copper) !important;}

#msc-mobile-menu .msc-mob-cta{
  display:block !important;margin-top:28px !important;text-align:center !important;
  font-family:var(--msc-font-sub) !important;font-size:0.78rem !important;
  font-weight:600 !important;letter-spacing:0.15em !important;text-transform:uppercase !important;
  color:var(--msc-cream-light) !important;background:var(--msc-dark-brown) !important;
  text-decoration:none !important;padding:14px 20px !important;
  border-radius:40px !important;border:none !important;
  box-shadow:0 2px 14px rgba(59,47,38,0.18) !important;
}
#msc-mobile-menu .msc-mob-cta:hover{
  background:var(--msc-copper) !important;
}

@media (max-width:960px){
  #msc-navbar .msc-nav-links{display:none !important;}
  #msc-navbar .msc-cta{display:none !important;}
  #msc-navbar .msc-hamburger{display:flex !important;}
  /* Panel slide */
  #msc-mobile-menu{
    transition:transform 0.32s cubic-bezier(0.4,0,0.2,1) !important;
    will-change:transform !important;
  }
  /* Hamburger spans → X */
  #msc-navbar .msc-hamburger span{
    transition:transform 0.28s cubic-bezier(0.4,0,0.2,1),opacity 0.28s cubic-bezier(0.4,0,0.2,1) !important;
  }
  /* Accordion sub-menus */
  #msc-mobile-menu .msc-mob-sub{
    transition:max-height 0.28s cubic-bezier(0.4,0,0.2,1) !important;
  }
  /* Arrow rotation */
  #msc-mobile-menu .msc-mob-arrow svg{
    transition:transform 0.28s cubic-bezier(0.4,0,0.2,1) !important;
  }
}
@media (max-width:480px){
  #msc-navbar .msc-nav-container{padding:0 20px !important;}
  #msc-navbar .msc-logo-img{height:40px !important;max-width:130px !important;}
  #msc-mobile-menu{width:280px !important;}
  #msc-mobile-menu .msc-mobile-inner{padding:14px 20px 36px !important;}
  #msc-navbar .msc-search-bar{padding:12px 20px !important;}
}

/* ── Skip-to-content link ── */
#msc-skip-link{
  position:fixed !important;top:-100% !important;left:16px !important;
  z-index:1000001 !important;
  font-family:var(--msc-font-sub) !important;font-size:0.85rem !important;font-weight:600 !important;
  color:var(--msc-cream-light) !important;background:var(--msc-dark-brown) !important;
  padding:10px 20px !important;border-radius:0 0 6px 6px !important;
  text-decoration:none !important;
  box-shadow:0 4px 16px rgba(59,47,38,0.2) !important;
}
#msc-skip-link:focus{
  top:0 !important;outline:none !important;
}

/* ── Active page indicators ── */
#msc-navbar .msc-nav-links li.msc-current>a{
  color:var(--msc-copper) !important;
  background-size:70% 1px !important;
}
#msc-navbar .msc-nav-links li.msc-current-ancestor>a{
  color:var(--msc-copper) !important;
}
#msc-navbar .msc-dropdown li.msc-current>a{
  color:var(--msc-copper) !important;
  background:rgba(214,173,96,0.14) !important;
  padding-left:26px !important;
}
#msc-mobile-menu li.msc-current>a{
  color:var(--msc-copper) !important;
}
#msc-mobile-menu li.msc-current-ancestor>.msc-mob-parent-row .msc-mob-parent{
  color:var(--msc-copper) !important;
}

/* ── Keyboard focus-visible ── */
#msc-navbar .msc-nav-links li a:focus-visible,
#msc-navbar .msc-cta:focus-visible,
#msc-navbar .msc-logo:focus-visible,
#msc-navbar .msc-icon-btn:focus-visible,
#msc-navbar .msc-search-input:focus-visible,
#msc-navbar .msc-search-submit:focus-visible,
#msc-navbar .msc-hamburger:focus-visible{
  outline:2px solid var(--msc-copper) !important;
  outline-offset:2px !important;
}
#msc-navbar .msc-dropdown li a:focus-visible{
  outline:2px solid var(--msc-copper) !important;
  outline-offset:-2px !important;
}
#msc-mobile-menu a:focus-visible,
#msc-mobile-menu button:focus-visible{
  outline:2px solid var(--msc-copper) !important;
  outline-offset:2px !important;
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion:reduce){
  #msc-navbar,#msc-navbar *,#msc-navbar *::before,#msc-navbar *::after,
  #msc-mobile-menu,#msc-mobile-menu *,
  #msc-panel-overlay{
    transition:none !important;animation:none !important;
  }
}

body.admin-bar #msc-navbar{top:32px !important;}
@media screen and (max-width:782px){
  body.admin-bar #msc-navbar{top:46px !important;}
}
</style>
<?php
    }

    /* ─── Navbar HTML + JS ────────────────────────────────── */

    public static function print_navbar() {
        if ( is_admin() ) return;
        $tree = self::get_menu_tree();
        if ( ! $tree ) return;

        /* Filterable settings */
        $logo_url  = esc_url( apply_filters( 'msc_navbar_logo_url',  home_url( '/' ) ) );
        $logo_img  = esc_url( apply_filters( 'msc_navbar_logo_img',  'https://www.masterseanchan.com/wp-content/uploads/2026/02/Sean-Chan-Logo-A9-Icon.png' ) );
        $logo_alt  = esc_attr( apply_filters( 'msc_navbar_logo_alt', 'Master Sean Chan – Feng Shui Master Singapore' ) );
        $site_name = esc_html( apply_filters( 'msc_navbar_site_name', 'Master Sean Chan' ) );
        $cta_text  = esc_html( apply_filters( 'msc_navbar_cta_text', 'Book Appointment' ) );
        $cta_url   = esc_url( apply_filters( 'msc_navbar_cta_url',  home_url( '/contact/' ) ) );
        $ig_url    = esc_url( apply_filters( 'msc_navbar_instagram_url', 'https://www.instagram.com/masterseanchan/' ) );
        $search_action = esc_url( home_url( '/' ) );

        $desktop = self::render_desktop_items( $tree );
        $mobile  = self::render_mobile_items( $tree );
?>
<a id="msc-skip-link" href="#main-content">Skip to content</a>

<nav id="msc-navbar" role="navigation" aria-label="<?php echo $logo_alt; ?>">
  <div class="msc-nav-container">
    <a class="msc-logo" href="<?php echo $logo_url; ?>" aria-label="<?php echo $logo_alt; ?> – Home">
      <img class="msc-logo-img" src="<?php echo $logo_img; ?>" alt="<?php echo $logo_alt; ?>" width="160" height="52" loading="eager" fetchpriority="high"/>
    </a>
    <ul class="msc-nav-links" role="menubar"><?php echo $desktop; ?></ul>
    <div class="msc-nav-actions">
      <a class="msc-icon-btn" href="<?php echo $ig_url; ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/><circle cx="17.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"/></svg>
      </a>
      <button class="msc-icon-btn" id="msc-search-toggle" aria-label="Search" aria-expanded="false" aria-controls="msc-search-bar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
      </button>
    </div>
    <a class="msc-cta" href="<?php echo $cta_url; ?>" aria-label="<?php echo $cta_text; ?>"><span><?php echo $cta_text; ?></span></a>
    <button class="msc-hamburger" id="msc-hamburger-btn" aria-expanded="false" aria-controls="msc-mobile-menu" aria-label="Open navigation menu"><span></span><span></span><span></span></button>
  </div>
  <div class="msc-search-bar" id="msc-search-bar">
    <form class="msc-search-form" action="<?php echo $search_action; ?>" method="get" role="search">
      <input class="msc-search-input" type="search" name="s" placeholder="Search articles, services..." aria-label="Search the site">
      <button class="msc-search-submit" type="submit">Search</button>
    </form>
  </div>
</nav>

<div id="msc-panel-overlay" aria-hidden="true"></div>

<div id="msc-mobile-menu" aria-hidden="true" role="dialog" aria-label="Navigation menu">
  <div class="msc-panel-header">
    <span class="msc-panel-logo"><?php echo $site_name; ?></span>
    <button class="msc-panel-close" id="msc-panel-close-btn" aria-label="Close navigation menu">&#x2715;</button>
  </div>
  <div class="msc-mobile-inner">
    <ul><?php echo $mobile; ?></ul>
    <a class="msc-mob-cta" href="<?php echo $cta_url; ?>"><?php echo $cta_text; ?></a>
    <div class="msc-mob-social">
      <a href="<?php echo $ig_url; ?>" target="_blank" rel="noopener noreferrer" aria-label="Follow on Instagram">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/><circle cx="17.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"/></svg>
        <span>@masterseanchan</span>
      </a>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';
  var navbar=document.getElementById('msc-navbar');
  var hamburger=document.getElementById('msc-hamburger-btn');
  var panel=document.getElementById('msc-mobile-menu');
  var overlay=document.getElementById('msc-panel-overlay');
  var closeBtn=document.getElementById('msc-panel-close-btn');
  if(!navbar||!hamburger||!panel)return;

  if(overlay&&overlay.parentElement!==document.body)document.body.appendChild(overlay);
  if(panel&&panel.parentElement!==document.body)document.body.appendChild(panel);
  if(navbar&&navbar.parentElement!==document.body)document.body.appendChild(navbar);

  hamburger=document.getElementById('msc-hamburger-btn');
  closeBtn=document.getElementById('msc-panel-close-btn');

  window.addEventListener('scroll',function(){
    navbar.classList.toggle('msc-scrolled',window.pageYOffset>20);
  },{passive:true});

  function setPanel(open){
    hamburger.classList.toggle('msc-open',open);
    hamburger.setAttribute('aria-expanded',String(open));
    panel.classList.toggle('msc-panel-open',open);
    panel.setAttribute('aria-hidden',String(!open));
    if(overlay){overlay.classList.toggle('msc-panel-open',open);overlay.setAttribute('aria-hidden',String(!open));}
    document.body.style.overflow=open?'hidden':'';
  }

  hamburger.addEventListener('click',function(){setPanel(!panel.classList.contains('msc-panel-open'));});
  if(closeBtn)closeBtn.addEventListener('click',function(){setPanel(false);});
  if(overlay)overlay.addEventListener('click',function(){setPanel(false);});

  panel.querySelectorAll('.msc-mob-parent').forEach(function(btn){
    btn.addEventListener('click',function(){
      var sub=document.getElementById(btn.getAttribute('aria-controls'));
      var row=btn.closest('.msc-mob-parent-row');
      var arrow=row?row.querySelector('.msc-mob-arrow'):null;
      var isOpen=sub.classList.contains('msc-open');
      sub.classList.toggle('msc-open',!isOpen);
      if(arrow)arrow.classList.toggle('msc-open',!isOpen);
      btn.setAttribute('aria-expanded',String(!isOpen));
    });
  });

  /* Search bar toggle */
  var searchToggle=document.getElementById('msc-search-toggle');
  var searchBar=document.getElementById('msc-search-bar');
  var searchInput=searchBar?searchBar.querySelector('.msc-search-input'):null;
  if(searchToggle&&searchBar){
    searchToggle.addEventListener('click',function(){
      var isOpen=searchBar.classList.contains('msc-open');
      searchBar.classList.toggle('msc-open',!isOpen);
      searchToggle.setAttribute('aria-expanded',String(!isOpen));
      if(!isOpen&&searchInput)searchInput.focus();
    });
  }

  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      setPanel(false);
      if(searchBar&&searchBar.classList.contains('msc-open')){
        searchBar.classList.remove('msc-open');
        searchToggle.setAttribute('aria-expanded','false');
        searchToggle.focus();
      } else {
        hamburger.focus();
      }
    }
  });

  /* Close search when clicking outside */
  document.addEventListener('click',function(e){
    if(searchBar&&searchBar.classList.contains('msc-open')&&!searchBar.contains(e.target)&&e.target!==searchToggle&&!searchToggle.contains(e.target)){
      searchBar.classList.remove('msc-open');
      searchToggle.setAttribute('aria-expanded','false');
    }
  });
})();
</script>
<?php
    }
}

MSC_Dynamic_Navbar::boot();
