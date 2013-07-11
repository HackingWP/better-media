<?php

/**
 * Better WordPress Media class
 *
 * Handles main plugin functionality
 *
 * @author  Martin Adamko <@martin_adamko>
 * @licence The MIT License <http://opensource.org/licenses/MIT>
 *
 */
class BetterWPMedia
{
    private $wpdb     = false;
    private $memcache = false;
    private $sizes    = null;
    private static $instance = null;

    /**
     * Constructor
     *
     * @param void
     *
     */
    private function __construct()
    {
        global $wpdb;
        $this->wpdb =& $wpdb;

        if (class_exists('Memcache') && apply_filters('BetterWPMedia/useMemcache', false)) {
            $memcache = new Memcache;

            if ($memcache->connect('localhost', 11211)) {
                $this->memcache =& $memcache;
            }
        }

        $this->sizes = BetterWPMediaSizes::instance();

        // Register media sizes
        add_action('init', function() {
            foreach ($this->sizes->get_sizes() as $name => $data) {
                add_image_size($name, $data['width'], $data['height'], $data['crop']);
            }
        });

        if (is_admin()) {
            add_action('activate_plugin',   array($this, 'activate'));
            add_action('deactivate_plugin', array($this, 'deactivate'));

            add_action('admin_init', array($this, 'register_media_setting_hires'));
        }

        if ((int) get_option('better_media_hash_names')===1) {
            add_filter('sanitize_file_name', array($this, 'hash_file_name'), 0, 2);
        }

        if ((int) get_option('better_media_force_small')===1) {
            add_filter('image_resize_dimensions', array(__CLASS__, 'image_resize_dimensions'), 10, 6);
        }

        add_filter('wp_get_attachment_metadata', array(__CLASS__,'generate_and_clean'), 10, 2);
    }

    /**
     * Returns singleton instance of this class
     *
     * @param       void
     * @returns     object  Instance of this class
     *
     */
    public static function instance()
    {
        if (static::$instance===null) {
            $instance = new BetterWPMedia();

            return $instance;
        }

        return static::$instance;
    }

    /**
     * Deactivates plugin
     *
     * @param void
     *
     */
    public function deactivate()
    {
    }

    /**
     * Activates plugin
     *
     * @param void
     *
     */
    public function activate()
    {
        $this->deactivate();
    }

    /**
     * Registers and adds plugin's settings fields on Media settings page
     *
     * @param void
     *
     */
    public function register_media_setting_hires()
    {
        add_settings_field('better_media_sizes', __('More sizes', __CLASS__), array($this, 'print_setting_sizes'), 'media', 'default');

        register_setting('media', 'better_media_hires', array($this, 'update_setting_hires'));
        add_settings_field('better_media_hires', __('Use high-res settings', __CLASS__), array($this, 'print_setting_hires'), 'media', 'default');

        register_setting('media', 'better_media_hash_names', array($this, 'update_setting_hash_names'));
        add_settings_field('better_media_hash_names', __('Hash names of uploaded media', __CLASS__), array($this, 'print_setting_hash_names'), 'media', 'default');

        register_setting('media', 'better_media_force_small', array($this, 'update_setting_force_small'));
        add_settings_field('better_media_force_small', __('Enlarge on resize', __CLASS__), array($this, 'print_setting_force_small'), 'media', 'default');
    }

    /**
     * Displays Media sizes Angular.js app
     *
     * @param void
     *
     */
    public function print_setting_sizes()
    {
        $data = array(
            '{{{pluginURL}}}' => admin_url('options-media.php'),
            '{{{sizes}}}'     => json_encode($this->sizes->get_sizes(true))
        );

        echo '<script src="'.plugins_url('/vendor/angular/angular.js/angular-1.0.7.min.js', dirname(__FILE__)).'"></script>';
        echo '<script src="'.plugins_url('/vendor/jashkenas/underscore/underscore-1.4.4.min.js', dirname(__FILE__)).'"></script>';
        echo '<script src="'.plugins_url('/vendor/mgonto/restangular/restangular-1.0.4.min.js', dirname(__FILE__)).'"></script>';
        echo strtr(file_get_contents(dirname(dirname(__FILE__)).'/templates/media-sizes.ng.html'), $data);
    }

    /**
     * Displays checkbox to enable uploads name hashing
     *
     * @param void
     *
     */
    public function print_setting_hash_names()
    {
        $checked = (int) get_option('better_media_hash_names')===1 ? ' checked="checked"' : '';
        echo '<input id="better_media_hash_names" name="better_media_hash_names" value="1" type="checkbox"'.$checked.' /> <label for="better_media_hash_names">'.__('Yes').'</label>';
    }

    /**
     * Displays checkbox to enable smaller sizes than originals to be build
     *
     * @param void
     *
     */
    public function print_setting_force_small()
    {
        $checked = (int) get_option('better_media_force_small')===1 ? ' checked="checked"' : '';
        echo '<input id="better_media_hash_names" name="better_media_force_small" value="1" type="checkbox"'.$checked.' /> <label for="better_media_force_small">'.__('Yes').'</label>';
    }

    /**
     * Displays checkbox to enable/disable (set/undo) high resolution settings
     *
     * @param void
     *
     */
    public function print_setting_hires()
    {
        $checked = (int) get_option('better_media_hires')===1 ? ' checked="checked"' : '';
        echo '<input id="better_media_hires" name="better_media_hires" value="1" type="checkbox"'.$checked.' /> <label for="better_media_hires">'.__('Yes').'</label>';
    }

    /**
     * Handle validation of posted value for name hashing checkbox
     *
     * @param void
     *
     */
    public function update_setting_hash_names($v = 0)
    {
        return (int) $v===1 ? 1 : 0;
    }

    /**
     * Handle validation of posted value for small images checkbox
     *
     * @param void
     *
     */
    public function update_setting_force_small($v = 0)
    {
        return (int) $v===1 ? 1 : 0;
    }

    /**
     * Init plugin
     *
     * Sets bigger thumbnails (retina), medium size gets push to 1280px, large to full HD 1920
     *
     * @param void
     *
     */
    public function update_setting_hires($v = 0)
    {
        switch ((int) $v) {
            case 1:
                $backup = array(
                    'thumbnail_size_w' => get_option('thumbnail_size_w'),
                    'thumbnail_size_h' => get_option('thumbnail_size_h'),
                    'thumbnail_crop'   => get_option('thumbnail_crop'),
                    'medium_size_w'    => get_option('medium_size_w'),
                    'medium_size_h'    => get_option('medium_size_h'),
                    'large_size_w'     => get_option('large_size_w'),
                    'large_size_h'     => get_option('large_size_h')
                );

                if (get_option('BetterWPMediaBkpSizes')===false && add_option('BetterWPMediaBkpSizes', $backup)) {
                    $default_media_sizes = array(
                        'thumbnail_size_w' =>  480, // Should look extra-crisp on retina displays
                        'thumbnail_size_h' =>  480, // and still be quite light to load but can be
                                                    // reused across design with less DB calls.

                        'thumbnail_crop'   =>    1, // Force aspect ratio crops are vital for nicer
                                                    // design.

                        'medium_size_w'    => 1136, // Should fit desktop wide enought and full screen
                        'medium_size_h'    => 1136, // on mobile devices shold look guite good.

                        'large_size_w'     => 1920, // For those edge cases like full-res backgrounds
                        'large_size_h'     => 1920  // or high demands
                    );

                    foreach ($default_media_sizes as $default_media_size => $default_media_size_value) {
                        $v = (int) apply_filters("BetterWPMedia/{$default_media_size}", $default_media_size_value);

                        // Sorry you have passed wrong value back...
                        if ($v <= 0) {
                            // except `thumbnail_crop`, but make it 0 in case <= 0
                            $v = $default_media_size ==='thumbnail_crop' ? 0 : $default_media_size_value;
                        }

                        update_option($default_media_size, $v);
                    }
                }

                return 1;
            break;
            default:
                // Load backup
                if ($default_media_sizes = get_option('BetterWPMediaBkpSizes')) {
                    foreach ($default_media_sizes as $default_media_size => $default_media_size_value) {
                        update_option($default_media_size, $default_media_size_value);
                    }
                }

                delete_option('BetterWPMediaBkpSizes');

                return 0;
            break;
        }
    }

    /**
     * Returns hash from identifier passed mixed with microtime()
     *
     * @param   mixed   $object Identifier
     * @returns string          Hash of transient
     *
     */
    private function hash($str = '')
    {
        return hash('sha256', json_encode(array($str,microtime())));
    }

    /**
     * Returns hashed filename
     *
     * @param  string $file  Full file name with extension
     * @return string        Hashed filename
     *
     */
    public function hash_file_name($file)
    {
        return $this->hash(get_file_filename($file)).'.'.get_file_extension($file);
    }

    /**
     * Respond to REST requests of media sizes Angular.js app
     *
     * @param void
     * @returns void
     *
     */
    public function respond()
    {
        $request = explode('/', trim(trim($_REQUEST['rest']), '/'));

        if (sizeof($request)<=0) {
            return;
        }

        $resource       =& $request[0];
        $method_or_size =  isset($request[1]) ? trim($request[1]) : 'index';

        switch ($resource) {
            case 'media-sizes':
                if ($this->sizes) {
                    switch ($_SERVER['REQUEST_METHOD']) {
                        case 'GET':
                            switch ($method_or_size) {
                                case 'index':
                                    status_header(200);
                                    echo json_encode($this->sizes->get_sizes(true));

                                    exit;
                                break;
                            }
                        break;
                        /****/
                        case 'PUT':
                            /* PUT data comes in on the stdin stream */
                            $putstream = fopen("php://input", "r");
                            $putdata   = stream_get_contents($putstream);
                            fclose($putstream);

                            echo json_encode(true);
                            exit;
                        break;
                        /****/
                        case 'DELETE':
                            $size =& $method_or_size;

                            if (! isset($request[1])) {
                                // DELETE ALL
                                status_header(500);

                                echo 'TODO: DELETE ALL';
                                exit;
                            }

                            if ($this->sizes->delete_size($size)) {
                                status_header(200);
                                echo json_encode(true, JSON_PRETTY_PRINT);

                                exit;
                            }

                            status_header(500);
                            echo json_encode(false);

                            exit;
                        break;
                        /****/
                        case 'POST':
                            if (!empty($GLOBALS['HTTP_RAW_POST_DATA'])) {
                                $size = json_decode($GLOBALS['HTTP_RAW_POST_DATA'], true);

                                if ($this->sizes->set_size($size)) {
                                    status_header(200);
                                    echo json_encode(true, JSON_PRETTY_PRINT);

                                    exit;
                                } else {
                                    status_header(500);
                                    echo json_encode(false);

                                    exit;
                                }
                            }

                            status_header(400);
                            exit;
                        break;
                    }
                }
            break;
        }

        status_header(404);
        exit;
    }

    /**
     * Add ability to generate image size even for smaller images than the actual thumb
     *
     * Image size is calculated in scale, even though image name has full resolution
     * {width}x{height} suffix.
     *
     * @param   mixed       $default    What to return by default
     * @param   int         $orig_w     Original width
     * @param   int         $orig_h     Original height
     * @param   int         $dest_w     New width
     * @param   int         $dest_h     New height
     * @param   bool        $crop       Optional, default is false. Whether to crop image or resize
     * @returns bool|array              False on failure. Returned array matches parameters for imagecopyresampled() PHP function
     *
     */
    public static function image_resize_dimensions($default = null, $orig_w, $orig_h, $dest_w, $dest_h, $crop)
    {
        $debug = false;
        if (($orig_w<=$dest_w || $orig_h<=$dest_h)) {
            if($debug) print_r('Function arguments: ', func_get_args());
            // Calculate new destination width
            if (!$crop) {
                if ($orig_w/$orig_h > $dest_w/$dest_h) {
                    if($debug) echo "Destination image is wider (original aspect ratio is bigger than destination).\n";
                    $dest_h = floor($dest_w * $orig_h / $orig_w);
                } else {
                    if($debug) echo "Destination image is narrower (original aspect ratio is smaller than destination).\n";
                    $dest_w = floor($dest_h * $orig_w / $orig_h);
                }
            }

            $aspect_ratio = $dest_w / $dest_h;

            if($debug) print_r('Partial: ', array( $orig_w, $orig_h, $dest_w, $dest_h ));

            if ( round($orig_h * $aspect_ratio) <= $orig_w ) {
                // Yay, we can use same width as original
                $new_h = $orig_h;
                $new_w = round($orig_h * $aspect_ratio);
            } else {
                // Ouh, we can have same height as original
                $new_w = $orig_w;
                $new_h = round($orig_w / $aspect_ratio);
            }

            if($debug) print_r('Partial: ', array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h ));

            if (!$new_w) {
                $new_w = intval($new_h * $aspect_ratio);
            }

            if (!$new_h) {
                $new_h = intval($new_w / $aspect_ratio);
            }

            if($debug) print_r('Partial: ', array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h ));

            $size_ratio = max($new_w / $orig_w, $new_h / $orig_h);

            $crop_w = round($new_w / $size_ratio);
            $crop_h = round($new_h / $size_ratio);

            if($debug) print_r('Partial: ', array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h ));

            $s_x = floor( ($orig_w - $crop_w) / 2 );
            $s_y = floor( ($orig_h - $crop_h) / 2 );

            // the return array matches the parameters to imagecopyresampled()
            // int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h

            if($debug) print_r('Partial: ', array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h ));

            $output = array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h );

            return $output;
        }

        return $default;
    }

    /**
     * Returns array of built-in WordPress image sizes
     *
     * @param   void
     * @returns array
     *
     */
    public static function wp_get_builtin_sizes()
    {
        return array(
            'thumbnail' => array('width' => get_option('thumbnail_size_w'), 'height' => get_option('thumbnail_size_h'), 'crop' => get_option('thumbnail_crop')),
            'medium' => array('width' => get_option('medium_size_w'), 'height' => get_option('medium_size_h'), 'crop' => get_option('medium_crop')),
            'large' => array('width' => get_option('large_size_w'), 'height' => get_option('large_size_h'), 'crop' => get_option('large_crop')),
        );
    }

    /**
     * Cleans and regenerates all image sizes (including) built-in
     *
     * @param   array   $data           Already fetched attachment metadata
     * @param   int     $attachment_id  Attachment ID
     * @returns array                   Modified metadata
     *
     */
    public static function generate_and_clean($data, $attachment_id)
    {
        global $_wp_additional_image_sizes;

        if (!is_array($data) || !isset($data['sizes']) || empty($attachment_id)) {
            return $data;
        }

        $uploads_dir = wp_upload_dir();
        $upload_path = $uploads_dir['basedir'];

        $current_sizes =& $data['sizes'];
        $default_sizes = array('thumbnail','medium','large');
        $all_sizes     = array_merge($_wp_additional_image_sizes, static::wp_get_builtin_sizes());

        $uploaded_file     = realpath($upload_path.'/'.$data['file']);
        $uploader_file_dir = realpath(dirname($uploaded_file));

        $dirty = false;

        foreach ($current_sizes as $size => &$metadata) {
            $expected_file = $uploader_file_dir.'/'.$metadata['file'];

            // Garbage collector - removes old thumbs
            if (!array_key_exists($size,$all_sizes)) {
                unset($current_sizes[$size]);
                @unlink($expected_file);
                $dirty = true;
            }

            // When files have been removed, clean the meta.
            // New thumbs will be regenerated in the next conditional.
            if (!file_exists($expected_file)) {
                unset($current_sizes[$size]);
                $dirty = true;
            }
        }

        if ( file_exists($uploaded_file) ) {
            // Check if additional sizes exist

            $_image_sizes = $all_sizes;

            foreach ($_image_sizes as $size => &$_data) {
                if (!isset($current_sizes[$size])) {
                    $editor = wp_get_image_editor( $uploaded_file );

//                     echo "\n\n{$size}: ";

                    if (is_wp_error($editor)) {
                        break;
                    }

                    $width  =&$_data['width'];
                    $height =&$_data['height'];
                    $crop   =&$_data['crop'];

                    $resized = $editor->resize( $width, $height, $crop );
                    if (is_wp_error($resized)) {
                        // Next thumbnail size
                        continue;
                    }

                    $suffix = $width.'x'.$height;
                    if ($crop) {
                        $suffix .='c';

                        // Create grayscale
                        if (method_exists($editor, 'grayscale') && strstr($size, 'grayscale')) {
                            if ($editor->grayscale()) {
                                $suffix .='-bw';
                            } else {
                                // Next thumbnail size
                                continue;
                            }
                        }
                    }

                    $dest_file = $editor->generate_filename($suffix);
                    $saved = $editor->save( $dest_file );

                    $editor->__destruct();

                    if (is_wp_error($saved)) {
                        // Something went terribly wrong, no more trying with other thumbs
                        break;
                    }

                    $dest_sizes = $editor->get_size();
                    if (!is_array($dest_sizes)) {
                        // Something went terribly wrong, no more trying with other thumbs
                        break;
                    }

                    $current_sizes[$size] = array(
                        'file' => basename($dest_file),
                        'width' => $dest_sizes['width'],
                        'height' => $dest_sizes['height'],
                        'mine-type' => static::get_mime_type(get_file_extension($dest_file))
                    );

                    $dirty = true;
                }
            }

            // TODO: remove unused, protect defaults

            if ($dirty) {
                update_post_meta($attachment_id, '_wp_attachment_metadata', $data);
            }
        }

        return $data;
    }

    /**
     * Returns mimetype by extension
     *
     * @param   string  $extension  File extension
     * @returns string              Mime type
     *
     */
    public static function get_mime_type($extension = null)
    {
        if ( ! $extension )
            return false;

        $mime_types = wp_get_mime_types();
        $extensions = array_keys( $mime_types );

        foreach ($extensions as $_extension) {
            if ( preg_match( "/{$extension}/i", $_extension ) ) {
                return $mime_types[$_extension];
            }
        }

        return false;
    }
}
