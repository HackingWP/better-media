<?php

/*
Plugin Name:    Better WordPress Media
Plugin URI:     http://www.attitude.sk
Description:    Tweak WordPress Media
Version:        v0.1.1
Author:         Martin Adamko
Author URI:     http://www.attitude.sk
License:        The MIT License (MIT)

Copyright (c) 2013 Mgr. art. Martin Adamko

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

*/

// Require dependencies
require_once 'library/global.php';

if (!function_exists('get_file_extension')) {
    require_once 'vendor/attitude/function-get_file_extension/function-get_file_extension.php';
}

if (!function_exists('get_file_filename')) {
    require_once 'vendor/attitude/function-get_file_filename/function-get_file_filename.php';
}

require_once 'library/BetterWPMedia.php';
require_once 'library/BetterWPMediaSizes.php';

// Init plugin
global $better_wp_media;
$better_wp_media = BetterWPMedia::instance();

add_action('admin_init', function() {
    global $better_wp_media;

    if ($GLOBALS['pagenow']==='options-media.php' && isset($_REQUEST['rest'])) {
        $better_wp_media->respond();

        exit;
    }
});

add_action('init', function() {
    require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
    require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
    require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

    class WP_Image_Editor_Imagick_Extended extends WP_Image_Editor_Imagick
    {
        public function grayscale()
        {
            return $this->image->modulateImage(100,0,100);
        }
    }

    class WP_Image_Editor_GD_Extended extends WP_Image_Editor_GD
    {
        public function grayscale()
        {
            return imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        }
    }
});

// Hook our own extended Editor Classes
add_filter('wp_image_editors', function($classes) {
    if (class_exists('WP_Image_Editor_GD_Extended')) {
        array_unshift($classes,'WP_Image_Editor_GD_Extended');
    }
    if (class_exists('WP_Image_Editor_Imagick_Extended')) {
        array_unshift($classes,'WP_Image_Editor_Imagick_Extended');
    }

    return $classes;
});
