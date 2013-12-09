<?php
/*
Plugin Name: wpForGlass
Plugin URI: http://labs.webershandwick.com/wpForGlass/
Description: Allows Google Glass explorers to share photos and videos from Glass straight to their wordpress blog through the Mirror API
Version: 1.0.0
Author: Weber Shandwick Labs
Author URI: http://labs.webershandwick.com/
Copyright: 2013 Ozzy Farman and Weber Shandwick

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/
require_once( plugin_dir_path( __FILE__ ) . 'libs/WpForGlass.php' );
$wpForGlass = new WpForGlass();
$wpForGlass->setupWPAdminPage();


register_deactivation_hook(__FILE__, array($wpForGlass,'deactivate'));

