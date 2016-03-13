<?php if(!defined('ABSPATH')) { die(); } // Include in all php files, to prevent direct execution
/**
  Plugin Name: WP Smart Crop
  Plugin URI: http://www.wpsmartcrop.com/
  Description: Style your images exactly how you want them to appear, for any screen size, and never get a cut-off face.
  Version: 1.0.0
  Author: WP SmartCrop
  Author URI: http://www.wpsmartcrop.com
  License: GPLv2 or later
  Text Domain: wpsmartcrop
*/

if( !class_exists('WP_Smart_Crop') ) {
	class WP_Smart_Crop {
		private $version = '1.0.0';
		private $plugin_dir;
		private static $_this;

		public static function Instance() {
			static $instance = null;
			if ($instance === null) {
				$instance = new self();
			}
			return $instance;
		}

		private function __construct() {
			$this->plugin_dir = plugin_dir_url( __FILE__ );
			// Editor Functions
			add_action( 'admin_head', array( $this, 'admin_head') );
			add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 10, 2 );
			add_action( 'edit_attachment', array( $this, 'edit_attachment' ) );
			// Display Functions
			add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), PHP_INT_MAX, 3 );
			add_filter( 'the_content', array( $this, 'the_content' ), PHP_INT_MAX );
		}

		function admin_head() {
			?>
			<style type="text/css">
				.wpsmartcrop_strip_pseudos:before {
					display: none !important;
				}
				.wpsmartcrop_strip_pseudos:after {
					display: none !important;
				}
			</style>
			<?php
		}
		function attachment_fields_to_edit( $form_fields, $post ) {
			if( substr($post->post_mime_type, 0, 5) == 'image' ) {
				$enabled = intval( get_post_meta( $post->ID, '_wpsmartcrop_enabled', true ) );
				$focus  = get_post_meta( $post->ID, '_wpsmartcrop_image_focus', true );
				if( !$focus || !is_array( $focus ) || !isset( $focus['left'] ) || !isset( $focus['top'] ) ) {
					$focus = array(
						'left' => '',
						'top'  => ''
					);
				}

				// build html for form interface
				ob_start();
				?>
				<input type="checkbox" class="wpsmartcrop_enabled" id="wpsmartcrop_enabled" name="attachments[<?php echo $post->ID; ?>][_wpsmartcrop_enabled]" value="1"<?php echo ( $enabled == 1 ) ? ' checked="checked"' : '';?> />
				<label for="wpsmartcrop_enabled">Enable Smart Cropping</label><br/>
				<input type="hidden"   class="wpsmartcrop_image_focus_left" name="attachments[<?php echo $post->ID; ?>][_wpsmartcrop_image_focus][left]" value="<?php echo $focus['left']; ?>" />
				<input type="hidden"   class="wpsmartcrop_image_focus_top"  name="attachments[<?php echo $post->ID; ?>][_wpsmartcrop_image_focus][top]"  value="<?php echo $focus['top' ]; ?>" />
				<em>Select a focal point for this image by clicking on the preview image</em>
				<script src="<?php echo $this->plugin_dir;?>js/media-library.js" type="text/javascript"></script>
				<?php
				$focal_point_html = ob_get_clean();
				$form_fields = array(
					'wpsmartcrop_image_focal_point' => array(
						'input' => 'html',
						'label' => __( 'Smart Crop' ),
						'html'  => $focal_point_html
					)
				) + $form_fields;
			}
			return $form_fields;
		}

		function edit_attachment( $attachment_id ) {
			if( isset( $_REQUEST['attachments'] ) && isset( $_REQUEST['attachments'][$attachment_id] ) ) {
				$attachment = $_REQUEST['attachments'][$attachment_id];

				if( isset( $attachment['_wpsmartcrop_enabled'] ) && $attachment['_wpsmartcrop_enabled'] == 1 ) {
					update_post_meta( $attachment_id, '_wpsmartcrop_enabled', 1 );
				} else {
					update_post_meta( $attachment_id, '_wpsmartcrop_enabled', 0 );
				}
				if( isset( $attachment['_wpsmartcrop_image_focus'] ) ) {
					update_post_meta( $attachment_id, '_wpsmartcrop_image_focus', $attachment['_wpsmartcrop_image_focus'] );
				} else {
					update_post_meta( $attachment_id, '_wpsmartcrop_image_focus', false );
				}
			}
		}

		function wp_enqueue_scripts() {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'wp-smart-crop-renderer', $this->plugin_dir . 'js/image-renderer.js', array( 'jquery' ), $this->version, true );
			wp_enqueue_style( 'wp-smart-crop-renderer', $this->plugin_dir . 'css/image-renderer.css', array(), $this->version );
		}

		function wp_get_attachment_image_attributes( $atts, $attachment, $size ) {
			$focus_attr = $this->get_smartcrop_focus_attr( $attachment->ID, $size );
			if( $focus_attr ) {
				if( !isset( $atts['class'] ) || !$atts['class'] ) {
					$atts['class'] = "";
				} else {
					$atts['class'] .= " ";
				}
				$atts['class'] .= "wpsmartcrop-image";
				$atts['data-smartcrop-focus'] = $focus_attr;
			}
			return $atts;
		}

		function the_content( $content ) {
			$tags = $this->extract_tags( $content, 'img', true, true );
			$unique_tags = array();
			$ids = array();
			foreach( $tags as $tag ) {
				list( $id, $size ) = $this->get_id_and_size_from_tag( $tag );
				if( $id && $size ) {
					$ids[] = $id;
					$tag['id'] = $id;
					$tag['size'] = $size;
					$unique_tags[$tag['full_tag']] = $tag;
				}
			}
			array_unique( $ids );
			if( count( $ids ) > 1 ) {
				update_meta_cache( 'post', $ids );
			}
			foreach( $unique_tags as $old_tag => $parsed_tag ) {
				$new_tag = $this->make_new_content_img_tag( $parsed_tag );
				if( $new_tag ) {
					$content = str_replace( $old_tag, $new_tag, $content );
				}
			}
			return $content;
		}

		private function get_smartcrop_focus_attr( $id, $size ) {
			// add an ID-keyed array cache here, so this function only has to run once per ID
			if( !$this->is_image_size_cropped( $size ) ) {
				if( get_post_meta( $id, '_wpsmartcrop_enabled', true ) == 1 ) {
					$focus = get_post_meta( $id, '_wpsmartcrop_image_focus', true );
					if( $focus && is_array( $focus ) && isset( $focus['left'] ) && isset( $focus['top'] ) ) {
						return json_encode( array(
							round( intval( $focus['left'] ), 2 ),
							round( intval( $focus['top' ] ), 2 ),
						) );
					}
				}
			}
			return false;
		}
		private function is_image_size_cropped( $size ) {
			$_wp_additional_image_sizes = $GLOBALS['_wp_additional_image_sizes'];
			if($size == 'full') {
				return false;
			}
			if( isset( $_wp_additional_image_sizes[ $size ] ) ) {
				return (bool) intval( $_wp_additional_image_sizes[ $size ]['crop'] );
			}
			if( in_array( $size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
				return (bool) intval( get_option( $size . "_crop" ) );
			}
			// if we can't find the size, lets assume it isnt cropped... it's a guess
			return false;
		}

		private function extract_tags( $html, $tag, $selfclosing = null, $return_the_entire_tag = false, $charset = 'ISO-8859-1' ){
			if ( is_array($tag) ){
				$tag = implode('|', $tag);
			}
			//known self-closing tabs
			$selfclosing_tags = array( 'area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta', 'col', 'param' );
			if ( is_null($selfclosing) ){
				$selfclosing = in_array( $tag, $selfclosing_tags );
			}
			//The regexp is different for normal and self-closing tags because I can't figure out
			//how to make a sufficiently robust unified one.
			if ( $selfclosing ){
				$tag_pattern =
					'@<(?P<tag>'.$tag.')           # <tag
					(?P<attributes>\s[^>]+)?       # attributes, if any
					\s*/?>                   # /> or just >, being lenient here
					@xsi';
			} else {
				$tag_pattern =
					'@<(?P<tag>'.$tag.')           # <tag
					(?P<attributes>\s[^>]+)?       # attributes, if any
					\s*>                 # >
					(?P<contents>.*?)         # tag contents
					</(?P=tag)>               # the closing </tag>
					@xsi';
			}
			$attribute_pattern =
				'@
				(?P<name>\w+)                         # attribute name
				\s*=\s*
				(
					(?P<quote>[\"\'])(?P<value_quoted>.*?)(?P=quote)    # a quoted value
					|                           # or
					(?P<value_unquoted>[^\s"\']+?)(?:\s+|$)           # an unquoted value (terminated by whitespace or EOF)
				)
				@xsi';
			//Find all tags
			if ( !preg_match_all($tag_pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ){
				//Return an empty array if we didn't find anything
				return array();
			}
			$tags = array();
			foreach ($matches as $match){
				//Parse tag attributes, if any
				$attributes = array();
				if ( !empty($match['attributes'][0]) ){
					if ( preg_match_all( $attribute_pattern, $match['attributes'][0], $attribute_data, PREG_SET_ORDER ) ){
						//Turn the attribute data into a name->value array
						foreach($attribute_data as $attr){
							if( !empty($attr['value_quoted']) ){
								$value = $attr['value_quoted'];
							} else if( !empty($attr['value_unquoted']) ){
								$value = $attr['value_unquoted'];
							} else {
								$value = '';
							}
							$attributes[$attr['name']] = $value;
						}
					}
				}
				$tag = array(
					'tag_name'   => $match['tag'][0],
					'offset'     => $match[0][1],
					'contents'   => !empty($match['contents'])?$match['contents'][0]:'', //empty for self-closing tags
					'attributes' => $attributes,
				);
				if ( $return_the_entire_tag ){
					$tag['full_tag'] = $match[0][0];
				}
				$tags[] = $tag;
			}

			return $tags;
		}
		private function get_id_and_size_from_tag( $tag ) {
			if( isset( $tag['attributes'] ) && isset( $tag['attributes']['class'] ) && $tag['attributes']['class'] ) {
				$classes     = explode( ' ', $tag['attributes']['class'] );
				$id_prefix   = 'wp-image-';
				$size_prefix = 'size-';
				$ret_val = array( false, false );
				foreach( $classes as $class ) {
					if( !$ret_val[0] && strpos( $class, $id_prefix ) === 0 ) {
						$ret_val[0] = intval( substr( $class, strlen( $id_prefix ) ) );
					} elseif( !$ret_val[1] && strpos( $class, $size_prefix ) === 0 ) {
						$ret_val[1] = substr( $class, strlen( $size_prefix ) );
					} elseif( $ret_val[0] && $ret_val[1] ) {
						break;
					}
				}
				return $ret_val;
			}
			return false;
		}
		private function make_new_content_img_tag( $tag ) {
			$id   = $tag['id'];
			$size = $tag['size'];
			$atts = $tag['attributes'];
			$focus_attr = $this->get_smartcrop_focus_attr( $id, $size );
			if( $focus_attr ) {
				if( !isset( $atts['class'] ) || !$atts['class'] ) {
					$atts['class'] = "";
				} else {
					$atts['class'] .= " ";
				}
				$atts['class'] .= "wpsmartcrop-image";
				$atts['data-smartcrop-focus'] = $focus_attr;
			}
			$new_tag = '<img';
			foreach( $atts as $name => $val ) {
				$new_tag .= ' ' . $name . '="' . $val . '"';
			}
			$new_tag .= ' />';
			return $new_tag;
		}
	}
	WP_Smart_Crop::Instance();
}