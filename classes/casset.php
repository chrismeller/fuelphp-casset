<?php

/**
 * Casset: Convenient asset library for FuelPHP.
 *
 * @package    Casset
 * @version    v1.11
 * @author     Antony Male
 * @license    MIT License
 * @copyright  2011 Antony Male
 * @link       http://github.com/canton7/fuelphp-casset
 */

namespace Casset;

class Casset {

	/**
	 * @var array Array of paths in which the css, js, img directory structure
	 *            can be found, relative to $asset_url
	 */
	protected static $asset_paths = array(
		'core' => 'assets/',
	);

	/*
	 * @var string The key in $asset_paths to use if no key is given
	 */
	protected static $default_path_key = 'core';

	/**
	 * @var string The URL to be prepanded to all assets.
	 */
	protected static $asset_url = null;

	/**
	 * @var array The folders in which css, js, and images can be found.
	 */
	protected static $default_folders = array(
		'css' => 'css/',
		'js' => 'js/',
		'img' => 'img/',
	);

	/**
	 * @var string The directory, relative to public/, where cached minified failes
	 *             are stored.
	 */
	protected static $cache_path = 'assets/cache/';

	/**
	 * @var array Holds groups of assets. Is documenented fully in the config file.
	 */
	protected static $groups = array(
		'css' => array(),
		'js' => array(),
	);

	/**
	 * @var array Holds inline js and css.
	 */
	protected static $inline_assets = array(
		'css' => array(),
		'js' => array(),
	);

	/**
	 * @var bool Whether to minfy.
	 */
	protected static $min_default = true;

	/**
	 * @var bool Whether to combine
	 */
	protected static $combine_default = true;

	/**
	 * @var bool Whether to render files inline by default.
	 */
	protected static $inline_default = false;

	/**
	 *
	 * @var array The default attributes when creating the asset's tag.
	 */
	protected static $attr_default = array();

	/**
	 * @var int How deep to go when resolving deps
	 */
	protected static $deps_max_depth = 5;

	/**
	 * @var bool Whether to show comments above the <script>/<link> tags showing
	 *           which files have been minified into that file.
	 */
	protected static $show_files = false;

	/**
	 * @var bool Whether to show comments inside minified files showing which
	 *           original file is where.
	 */
	protected static $show_files_inline = false;

	/**
	 * @var function If given, the function to call when we've read a file, before
	 * minifying.
	 * Note that it's only called if $combine for the file is true
	 * Prototype: callback(content, filename, type, group_name);
	 */
	protected static $post_load_callback = null;

	/**
	 * @var array Keeps a record of which groups have been rendered.
	 * We then check this when deciding whether to render a dep.
	 */
	protected static $rendered_groups = array('js' => array(), 'css' => array());

	/**
	 * @var bool Wether we've been initialized.
	 */
	public static $initialized = false;

	/**
	* Loads in the config and sets the variables
	*/
	public static function _init()
	{
		// Prevent multiple initializations
		if (static::$initialized)
		{
			return;
		}

		\Config::load('casset', true);

		$paths = \Config::get('casset.paths', static::$asset_paths);

		foreach($paths as $key => $path)
		{
			static::add_path($key, $path);
		}

		static::$asset_url = \Config::get('casset.url', \Config::get('base_url'));

		static::$default_folders = array(
			'css' => \Config::get('casset.css_dir', static::$default_folders['css']),
			'js' => \Config::get('casset.js_dir', static::$default_folders['js']),
			'img' => \Config::get('casset.img_dir', static::$default_folders['img']),
		);

		static::$cache_path = \Config::get('casset.cache_path', static::$cache_path);

		static::$min_default = \Config::get('casset.min', static::$min_default);
		static::$combine_default = \Config::get('casset.combine', static::$combine_default);

		static::$deps_max_depth = \Config::get('casset.deps_max_depth', static::$deps_max_depth);


		$group_sets = \Config::get('casset.groups', array());

		foreach ($group_sets as $group_type => $groups)
		{
			foreach ($groups as $group_name => $group)
			{
				$options = array(
					'enabled' => array_key_exists('enabled', $group) ? $group['enabled'] : true,
					'combine' => array_key_exists('combine', $group) ? $group['combine'] : static::$combine_default,
					'min' => array_key_exists('min', $group) ? $group['min'] : static::$min_default,
					'inline' => array_key_exists('inline', $group) ? $group['inline'] : static::$inline_default,
					'attr' => array_key_exists('attr', $group) ? $group['attr'] : static::$attr_default,
					'deps' => array_key_exists('deps', $group) ? $group['deps'] : array(),
				);
				static::add_group($group_type, $group_name, $group['files'], $options);
			}
		}

		static::$show_files = \Config::get('casset.show_files', static::$show_files);
		static::$show_files_inline = \Config::get('casset.show_files_inline', static::$show_files_inline);

		static::$post_load_callback = \Config::get('casset.post_load_callback', static::$post_load_callback);

		static::$initialized = true;
	}



	/**
	 * Parses oen of the 'paths' config keys into the format used internally.
	 * Config file format:
	 * 'paths' => array(
	 *		'assets/',
	 *		array(
	 *			'path' => 'assets_2/',
	 *			'js_dir' => 'js/',
	 *			'css_dir' => 'css/',
	 *		),
	 * ),
	 * In the event that the value isn't an array, it is turned into one.
	 * If js_dir, css_dir or img_dir are not given, they are populated with
	 * the defaults, giving in the 'js_dir', 'css_dir' and 'img_dir' config keys.
	 * @param string $path_key the key of the path
	 * @param mixed $path_attr the path attributes, as described above
	 */
	public static function add_path($path_key, $path_attr)
	{
		$path_val = array();
		if (!is_array($path_attr))
			$path_attr = array('path' => $path_attr, 'dirs' => array());
		elseif (!array_key_exists('dirs', $path_attr))
			$path_attr['dirs'] = array();

		$path_val['path'] = $path_attr['path'];
		$path_val['dirs'] = array(
			'js' => array_key_exists('js_dir', $path_attr) ? $path_attr['js_dir'] : static::$default_folders['js'],
			'css' => array_key_exists('css_dir', $path_attr) ? $path_attr['css_dir'] : static::$default_folders['css'],
			'img' => array_key_exists('img_dir', $path_attr) ? $path_attr['img_dir'] : static::$default_folders['img'],
		);
		static::$asset_paths[$path_key] = $path_val;
	}


	/**
	 * Set the current default path
	 *
	 * @param $path_key the path key to set the default to.
	 */
	public static function set_path($path_key = 'core')
	{
		if (!array_key_exists($path_key, static::$asset_paths))
			throw new Casset_Exception("Asset path key $path_key doesn't exist");
		static::$default_path_key = $path_key;
	}

	/**
	 * Adds a group of assets. If a group of this name exists, the function returns.
	 *
	 * @param string $group_type 'js' or 'css'
	 * @param string $group_name The name of the group
	 * @param array $options. An array of options. array(
	 *   'enabled' => true/false,
	 *   'combine' => true/false,
	 *   'min' => true/false,
	 *   'inline' => true/false,
	 *	 'deps' => array(),
	 * );
	 */
	private static function add_group_base($group_type, $group_name, $options = array())
	{
		// Insert defaults
		$options = array_merge(array(
			'enabled' => true,
			'combine' => static::$combine_default,
			'min' => static::$min_default,
			'inline' => static::$inline_default,
			'attr' => static::$attr_default,
			'deps' => array(),
		), $options);
		if (!is_array($options['deps']))
			$options['deps'] = array($options['deps']);
		$options['files'] = array();
		// If it already exists, don't overwrite it
		if (array_key_exists($group_name, static::$groups[$group_type]))
			throw new Casset_Exception("Group $group_name already exists: can't create it.");
		static::$groups[$group_type][$group_name] = $options;
	}

	/**
	 * Adds a group for assets, and adds assets to that group.
	 *
	 * @param string $group_type 'js' or 'css'
	 * @param string $group_name The name of the group
	 * @param array $options. An array of options. array(
	 *   'enabled' => true/false,
	 *   'combine' => true/false,
	 *   'min' => true/false,
	 *   'inline' => true/false,
	 *   'attr' => array(),
	 *   'deps' => array(),
	 * );
	 * To maintain backwards compatibility, you can also pass $enabled here.
	 * @param bool $combine_dep DEPRECATED. Whether to combine files in this group. Default (null) means use config setting
	 * @param boo $min_dep DEPRECATED/ Whether to minify files in this group. Default (null) means use config setting
	 */
	public static function add_group($group_type, $group_name, $files, $options = array(), $combine_dep = null, $min_dep = null)
	{
		// Bit of backwards compatibity.
		// Order used to be add_group(group_type, group_name, files, enabled, combine, min)
		if (!is_array($options))
		{
			$options = array(
				'enabled' => $options,
				'combine' => $combine_dep,
				'min' => $min_dep,
			);
		}
		// We're basically faking the old add_group. However, the approach has changed since those days
		// Therefore we create the group it it doesn't already exist, then add the files to it
		static::add_group_base($group_type, $group_name, $options);
		foreach ($files as $file) {
			if (!is_array($file))
				$file = array($file, false);
			static::add_asset($group_type, $file[0], $file[1], $group_name);
		}
	}

	/**
	 * Enables both js and css groups of the given name.
	 *
	 * @param mixed $group The group to enable, or array of groups
	 */
	public static function enable($groups)
	{
		static::asset_enabled('js', $groups, true);
		static::asset_enabled('css', $groups, true);
	}

	/**
	 * Disables both js and css groups of the given name.
	 *
	 * @param string $group The group to disable, or array of groups
	 */
	public static function disable($groups)
	{
		static::asset_enabled('js', $groups, false);
		static::asset_enabled('css', $groups, false);
	}

	/**
	 * Enable a group of javascript assets.
	 *
	 * @param string $group The group to enable, or array of groups
	 */
	public static function enable_js($groups)
	{
		static::asset_enabled('js', $groups, true);
	}

	/**
	 * Disable a group of javascript assets.
	 *
	 * @param string $group The group to disable, or array of groups
	 */
	public static function disable_js($groups)
	{
		static::asset_enabled('js', $groups, false);
	}

	/**
	 * Enable a group of css assets.
	 *
	 * @param string $group The group to enable, or array of groups
	 */
	public static function enable_css($groups)
	{
		static::asset_enabled('css', $groups, true);
	}

	/**
	 * Disable a group of css assets.
	 *
	 * @param string $group The group to disable, or array of groups
	 */
	public static function disable_css($groups)
	{
		static::asset_enabled('css', $groups, false);
	}

	/**
	 * Enables / disables an asset.
	 *
	 * @param string $type 'css' / 'js'
	 * @param string $group The group to enable/disable, or array of groups
	 * @param bool $enabled True to enabel to group, false odisable
	 */
	private static function asset_enabled($type, $groups, $enabled)
	{
		if (!is_array($groups))
			$groups = array($groups);
		foreach ($groups as $group)
		{
			// If the group doesn't exist it's of no consequence
			if (!array_key_exists($group, static::$groups[$type]))
				continue;
			static::$groups[$type][$group]['enabled'] = $enabled;
		}
	}

	/**
	 * Set group options on-the-fly.
	 *
	 * @param string $type 'js' / 'css
	 * @param mixed $group_names Group name to change, or array of groups to change,
	 *		or '' for global group, or '*' for all groups.
	 * @param string $option_key The name of the option to change
	 * @param mixed $option_value What to set the option to
	 */
	public static function set_group_option($type, $group_names, $option_key, $option_value)
	{
		if ($group_names == '')
			$group_names = array('global');
		else if ($group_names == '*')
			$group_names = array_keys(static::$groups[$type]);
		else if (!is_array($group_names))
			$group_names = array($group_names);

		// Allow them to specify a single string dep
		if ($option_key == 'deps' && !is_array($option_value))
			$option_value = array($option_value);

		foreach ($group_names as $group_name)
			static::$groups[$type][$group_name][$option_key] = $option_value;
	}

	/**
	 * Set group options on-the-fly, js version
	 *
	 * @param mixed $group_names Group name to change, or array of groups to change,
	 *		or '' for global group, or '*' for all groups.
	 * @param string $option_key The name of the option to change
	 * @param mixed $option_value What to set the option to
	 */
	public static function set_js_option($group_names, $option_key, $option_value)
	{
		static::set_group_option('js', $group_names, $option_key, $option_value);
	}

	/**
	 * Set group options on-the-fly, css version
	 *
	 * @param mixed $group_names Group name to change, or array of groups to change,
	 *		or '' for global group, or '*' for all groups.
	 * @param string $option_key The name of the option to change
	 * @param mixed $option_value What to set the option to
	 */
	public static function set_css_option($group_names, $option_key, $option_value)
	{
		static::set_group_option('css', $group_names, $option_key, $option_value);
	}

	/**
	 * Add a javascript asset.
	 *
	 * @param string $script The script to add.
	 * @param string $script_min If given, will be used when $min = true
	 *        If omitted, $script will be minified internally
	 * @param string $group The group to add this asset to. Defaults to 'global'
	 */
	public static function js($script, $script_min = false, $group = 'global')
	{
		static::add_asset('js', $script, $script_min, $group);
	}

	/**
	 * Add a css asset.
	 *
	 * @param string $sheet The script to add
	 * @param string $sheet_min If given, will be used when $min = true
	 *        If omitted, $script will be minified internally
	 * @param string $group The group to add this asset to. Defaults to 'global'
	 */
	public static function css($sheet, $sheet_min = false, $group = 'global')
	{
		static::add_asset('css', $sheet, $sheet_min, $group);
	}

	/**
	 * Abstraction of js() and css().
	 *
	 * @param string $type 'css' / 'js'
	 * @param string $script The script to add.
	 * @param string $script_min If given, will be used when $min = true
	 *        If omitted, $script will be minified internally
	 * @param string $group The group to add this asset to
	 */
	private static function add_asset($type, $script, $script_min, $group)
	{
		// Don't force the user to remember that 'false' is used when not supplying
		// a pre-minified file.
		if (!is_string($script_min))
			$script_min = false;
		$files = array($script, $script_min);
		// If the user hasn't specified a path key, add $default_path_key
		foreach ($files as &$file)
		{
			if ($file != false && strpos($file, '::') === false)
				$file = static::$default_path_key.'::'.$file;
		}

		if (!array_key_exists($group, static::$groups[$type]))
		{
			// Assume they want the group enabled
			static::add_group_base($type, $group);
		}
		array_push(static::$groups[$type][$group]['files'], $files);
	}

	/**
	 * Add a string containing javascript, which can be printed inline with
	 * js_render_inline().
	 *
	 * @param string $content The javascript to add
	 */
	public static function js_inline($content)
	{
		static::add_asset_inline('js', $content);
	}

	/**
	 * Add a string containing css, which can be printed inline with
	 * css_render_inline().
	 *
	 * @param string $content The css to add
	 */
	public static function css_inline($content)
	{
		static::add_asset_inline('css', $content);
	}

	/**
	 * Abstraction of js_inline() and css_inline().
	 *
	 * @param string $type 'css' / 'js'
	 * @param string $content The css / js to add
	 */
	private static function add_asset_inline($type, $content)
	{
		array_push(static::$inline_assets[$type], $content);
	}


	/**
	 * Return the path for the given JS asset. Ties into find_files, so supports
	 * everything that, say, Casset::js() does.
	 * Throws an exception if the file isn't found.
	 * @param string $script the name of the asset to find
	 * @param bool $add_url whether to add the 'url' config key to the filename
	 * @param bool $force_array by default, when one file is found a string is
	 *		returned. Setting this to true causes a single-element array to be returned.
	 */
	public static function get_filepath_js($filename, $add_url = false, $force_array = false)
	{
		return static::get_filepath($filename, 'js', $add_url, $force_array);
	}

	/**
	 * Return the path for the given CSS asset. Ties into find_files, so supports
	 * everything that, say, Casset::js() does.
	 * Throws an exception if the file isn't found.
	 * @param string $script the name of the asset to find
	 * @param bool $add_url whether to add the 'url' config key to the filename
	 * @param bool $force_array by default, when one file is found a string is
	 *		returned. Setting this to true causes a single-element array to be returned.
	 */
	public static function get_filepath_css($filename, $add_url = false, $force_array = false)
	{
		return static::get_filepath($filename, 'css', $add_url, $force_array);
	}

	/**
	 * Return the path for the given img asset. Ties into find_files, so supports
	 * everything that, say, Casset::js() does.
	 * Throws an exception if the file isn't found.
	 * @param string $script the name of the asset to find
	 * @param bool $add_url whether to add the 'url' config key to the filename
	 * @param bool $force_array by default, when one file is found a string is
	 *		returned. Setting this to true causes a single-element array to be returned.
	 */
	public static function get_filepath_img($filename, $add_url = false, $force_array = false)
	{
		return static::get_filepath($filename, 'img', $add_url, $force_array);
	}

	/**
	 * Return the path for the given asset. Ties into find_files, so supports
	 * everything that, say, Casset::js() does.
	 * Throws an exception if the file isn't found.
	 * @param string $script the name of the asset to find
	 * @param string $type js, css or img
	 * @param bool $add_url whether to add the 'url' config key to the filename
	 * @param bool $force_array by default, when one file is found a string is
	 *		returned. Setting this to true causes a single-element array to be returned.
	 */
	public static function get_filepath($filename, $type, $add_url = false, $force_array = false)
	{
		if (strpos($filename, '::') === false)
			$filename = static::$default_path_key.'::'.$filename;
		$files = static::find_files($filename, $type);
		if ($add_url)
		{
			foreach ($files as &$file)
			{
				if (strpos($file, '//') !== false)
					continue;
				$file = static::$asset_url.$file;
			}
		}
		if (count($files) == 1 && !$force_array)
			return $files[0];
		return $files;
	}

	/**
	 * Can be used to add deps to a group.
	 *
	 * @param string $type 'js' / 'css
	 * @param string $group The group name to add deps to
	 * @param array $deps An array of group names to add as deps.
	 */
	public static function add_deps($type, $group, $deps)
	{
		if (!is_array($deps))
			$deps = array($deps);
		if (!array_key_exists($group, static::$groups[$type]))
			throw new \Fuel_Exception("Group $group ($type) doesn't exist, so can't add deps to it.");
		array_push(static::$groups[$type][$group]['deps'], $deps);
	}

	/**
	 * Sugar for add_deps(), for js groups
	 * @param string $group The group name to add deps to
	 * @param array $deps An array of group names to add as deps.
	 */
	public static function add_js_deps($group, $deps)
	{
		static::add_deps('js', $group, $deps);
	}

	/**
	 * Sugar for add_deps(), for css groups
	 * @param string $group The group name to add deps to
	 * @param array $deps An array of group names to add as deps.
	 */
	public static function add_css_deps($group, $deps)
	{
		static::add_deps('css', $group, $deps);
	}

	/**
	 * Shortcut to render_js() and render_css().
	 *
	 * @param string $group Which group to render. If omitted renders all groups
	 * @param bool $inline_dep DEPRECATED. If true, the result is printed inline. If false, is
	 *        written to a file and linked to. In fact, $inline = true also causes
	 *        a cache file to be written for speed purposes
	 * @return string The javascript tags to be written to the page
	 */
	public static function render($group = false, $inline_dep = null, $attr = array())
	{
		$r = static::render_css($group, $inline_dep, $attr);
		$r.= static::render_js($group, $inline_dep, $attr);
		return $r;
	}

	/**
	 * Renders the specific javascript group, or all groups if no group specified.
	 *
	 * @param string $group Which group to render. If omitted renders all groups
	 * @param bool $inline_dep DEPRECATED. If true, the result is printed inline. If false, is
	 *        written to a file and linked to. In fact, $inline = true also causes
	 *        a cache file to be written for speed purposes
	 * @return string The javascript tags to be written to the page
	 */
	public static function render_js($group = false, $inline = null, $attr = array())
	{
		// Don't force the user to remember that false is used for ommitted non-bool arguments
		if (!is_string($group))
			$group = false;
		if (!is_array($attr))
			$attr = array();

		$file_groups = static::files_to_render('js', $group);

		$ret = '';

		foreach ($file_groups as $group_name => $file_group)
		{
			// We used to take $inline as 2nd argument. However, we now use a group option.
			// It's easiest if we let $inline override this group option, though.
			if ($inline === null)
				$inline = static::$groups['js'][$group_name]['inline'];
			// $attr is also deprecated. If specified, entirely overrides the group option.
			if (!count($attr))
				$attr = static::$groups['js'][$group_name]['attr'];

			if (static::$groups['js'][$group_name]['combine'])
			{
				$filename = static::combine('js', $file_group, static::$groups['js'][$group_name]['min'], $inline);
				if (!$inline && static::$show_files)
				{
					$ret .= '<!--'.PHP_EOL.'Group: '.$group_name.PHP_EOL.implode('', array_map(function($a){
						return "\t".$a['file'].PHP_EOL;
					}, $file_group)).'-->'.PHP_EOL;
				}
				if ($inline)
					$ret .= html_tag('script', array('type' => 'text/javascript')+$attr, PHP_EOL.file_get_contents(DOCROOT.static::$cache_path.$filename).PHP_EOL).PHP_EOL;
				else
					$ret .= html_tag('script', array(
						'type' => 'text/javascript',
						'src' => static::$asset_url.static::$cache_path.$filename,
					)+$attr, '').PHP_EOL;
			}
			else
			{
				foreach ($file_group as $file)
				{
					if ($inline)
						$ret .= html_tag('script', array('type' => 'text/javascript')+$attr, PHP_EOL.file_get_contents($file['file']).PHP_EOL).PHP_EOL;
					else
					{
						$base = (strpos($file['file'], '//') === false) ? static::$asset_url : '';
						$ret .= html_tag('script', array(
							'type' => 'text/javascript',
							'src' => $base.$file['file'],
						)+$attr, '').PHP_EOL;
					}
				}
			}
		}
		return $ret;
	}

	/**
	 * Renders the specific css group, or all groups if no group specified.
	 *
	 * @param string $group Which group to render. If omitted renders all groups
	 * @param bool $inline DEPRECATED. If true, the result is printed inline. If false, is
	 *        written to a file and linked to. In fact, $inline = true also causes
	 *        a cache file to be written for speed purposes
	 * @return string The css tags to be written to the page
	 */
	public static function render_css($group = false, $inline = null, $attr = array())
	{
		// Don't force the user to remember that false is used for ommitted non-bool arguments
		if (!is_string($group))
			$group = false;
		if (!is_array($attr))
			$attr = array();

		$file_groups = static::files_to_render('css', $group);

		$ret = '';

		foreach ($file_groups as $group_name => $file_group)
		{
			// We used to take $inline as 2nd argument. However, we now use a group option.
			// It's easiest if we let $inline override this group option, though.
			if ($inline === null)
				$inline = static::$groups['css'][$group_name]['inline'];
			// $attr is also deprecated. If specified, entirely overrides the group option.
			if (!count($attr))
				$attr = static::$groups['css'][$group_name]['attr'];

			if (static::$groups['css'][$group_name]['combine'])
			{

				$filename = static::combine('css', $file_group, static::$groups['css'][$group_name]['min'], $inline);
				if (!$inline && static::$show_files)
				{
					$ret .= '<!--'.PHP_EOL.'Group: '.$group_name.PHP_EOL.implode('', array_map(function($a){
						return "\t".$a['file'].PHP_EOL;
					}, $file_group)).'-->'.PHP_EOL;
				}
				if ($inline)
					$ret .= html_tag('style', array('type' => 'text/css')+$attr, PHP_EOL.file_get_contents(DOCROOT.static::$cache_path.$filename).PHP_EOL).PHP_EOL;
				else
					$ret .= html_tag('link', array(
						'rel' => 'stylesheet',
						'type' => 'text/css',
						'href' => static::$asset_url.static::$cache_path.$filename,
					)+$attr).PHP_EOL;
			}
			else
			{
				foreach ($file_group as $file)
				{
					if ($inline)
						$ret .= html_tag('style', array('type' => 'text/css')+$attr, PHP_EOL.file_get_contents($file['file']).PHP_EOL).PHP_EOL;
					else
					{
						$base = (strpos($file['file'], '//') === false) ? static::$asset_url : '';
						$ret .= html_tag('link', array(
							'rel' => 'stylesheet',
							'type' => 'text/css',
							'href' => $base.$file['file'],
						)+$attr).PHP_EOL;
					}
				}
			}
		}
		return $ret;
	}

	/**
	 * Figures out where a file should be, based on its namespace and type.
	 *
	 * @param string $file The name of the asset to search for
	 * @param string $asset_type 'css', 'js' or 'img'
	 * @return string The path to the asset, relative to $asset_url
	 */
	private static function find_files($file, $asset_type)
	{
		$parts = explode('::', $file, 2);
		if (!array_key_exists($parts[0], static::$asset_paths))
			throw new Casset_Exception("Could not find namespace {$parts[0]}");

		$path = static::$asset_paths[$parts[0]]['path'];
		$file = $parts[1];

		$folder = static::$asset_paths[$parts[0]]['dirs'][$asset_type];
		$file = ltrim($file, '/');

		$remote = (strpos($path, '//') !== false);

		if ($remote)
		{
			// Glob doesn't work on remote locations, so just assume they
			// specified a file, not a glob pattern.
			// Don't look for the file now either. That'll be done by
			// file_get_contents later on, if need be.
			return array($path.$folder.$file);
		}
		else
		{
			$glob_files = glob($path.$folder.$file);
			if (!$glob_files || !count($glob_files))
				throw new Casset_Exception("Found no files matching $path$folder$file");
			return $glob_files;
		}
	}

	/**
	 * Given a list of group names, adds to that list, in the appropriate places,
	 * and groups which are listed as dependencies of those group.
	 * Duplicate group names are not a problem, as a group is disabled when it's
	 * rendered.
	 *
	 * @param string $type 'js' /or/ 'css'
	 * @param array $group_names Array of group names to check
	 * @param int $depth Used by this function to check for potentially infinite recursion
	 * @return array List of group names with deps resolved
	 */

	private static function resolve_deps($type, $group_names, $depth=0)
	{
		if ($depth > static::$deps_max_depth)
		{
			throw new Casset_Exception("Reached depth $depth trying to resolve dependencies. ".
					"You've probably got some circular ones involving ".implode(',', $group_names).". ".
					"If not, adjust the config key deps_max_depth.");
		}
		// Insert the dep just before what it's a dep for
		foreach ($group_names as $i => $group_name)
		{
			// If the group's already been rendered, bottle
			if (in_array($group_name, static::$rendered_groups[$type]))
				continue;
			// Don't pay attention to bottom-level groups which are disabled
			if (!static::$groups[$type][$group_name]['enabled'] && $depth == 0)
				continue;
			// Otherwise, enable the group. Fairly obvious, as the whole point of
			// deps is to render disabled groups
			static::asset_enabled($type, $group_name, true);
			if (count(static::$groups[$type][$group_name]['deps']))
			{
				array_splice($group_names, $i, 0, static::resolve_deps($type, static::$groups[$type][$group_name]['deps'], $depth+1));
			}
		}
		return $group_names;
	}

	/**
	 * Determines the list of files to be rendered, along with whether they
	 * have been minified already.
	 *
	 * @param string $type 'css' / 'js'
	 * @param array $group The groups to render. If false, takes all groups
	 * @return array An array of array('file' => file_name, 'minified' => whether_minified)
	 */
	private static function files_to_render($type, $group)
	{
		// If no group specified, print all groups.
		if ($group == false)
			$group_names = array_keys(static::$groups[$type]);
		// If a group was specified, but it doesn't exist
		else if (!array_key_exists($group, static::$groups[$type]))
			return array();
		else
			$group_names = array($group);

		$files = array();

		$minified = false;

		$group_names = static::resolve_deps($type, $group_names);

		foreach ($group_names as $group_name)
		{
			if (static::$groups[$type][$group_name]['enabled'] == false)
				continue;
			// If there are no files in the group, there's no point in printing it.
			if (count(static::$groups[$type][$group_name]['files']) == 0)
				continue;

			$files[$group_name] = array();

			// Mark the group as disabled to avoid the same group being printed twice
			static::asset_enabled($type, $group_name, false);
			// Add it to the list of rendered groups
			array_push(static::$rendered_groups[$type], $group_name);

			foreach (static::$groups[$type][$group_name]['files'] as $file_set)
			{
				if (static::$groups[$type][$group_name]['min'])
				{
					$assets = static::find_files(($file_set[1]) ? $file_set[1] : $file_set[0], $type);
					$minified = ($file_set[1] != false);
				}
				else
				{
					$assets = static::find_files($file_set[0], $type);
				}
				foreach ($assets as $file) {
					array_push($files[$group_name], array(
						'file' => $file,
						'minified' => $minified,
					));
				}
			}
		}
		return $files;
	}

	/**
	 * Used to load a file from disk.
	 * Also calls the post_load callback.
	 *
	 * @param type $filename
	 * @return type
	 */
	private static function load_file($filename, $type, $file_group)
	{
		$content = file_get_contents($filename);
		if (static::$post_load_callback != null)
		{
			// For some reason, PHP doesn't like you calling member closure directly
			$func = static::$post_load_callback;
			$content = $func($content, $filename, $type, $file_group);
		}
		return $content;
	}

	/**
	 * Takes a list of files, and combines them into a single minified file.
	 * Doesn't bother if none of the files have been modified since the cache
	 * file was written.
	 *
	 * @param string $type 'css' / 'js'
	 * @param array $file_group Array of ('file' => filename, 'minified' => is_minified)
	 *        to combine and minify.
	 * @param bool $minify whether to minify the files, as well as combining them
	 * @return string The path to the cache file which was written.
	 */
	private static function combine($type, $file_group, $minify, $inline)
	{
		$filename = md5(implode('', array_map(function($a) {
			return $a['file'];
		}, $file_group)).($minify ? 'min' : '')).'.'.$type;

		// Get the last modified time of all of the component files
		$last_mod = 0;
		foreach ($file_group as $file)
		{
			// If it's a remote file just assume it isn't modified, otherwise
			// we're stuck making a ton of HTTP requests
			if (strpos($file['file'], '//') !== false)
				continue;

			$mod = filemtime(DOCROOT.$file['file']);
			if ($mod > $last_mod)
				$last_mod = $mod;
		}

		$filepath = DOCROOT.static::$cache_path.'/'.$filename;
		$needs_update = (!file_exists($filepath) || ($mtime = filemtime($filepath)) < $last_mod);

		if ($needs_update)
		{
			$content = '';
			foreach ($file_group as $file)
			{
				if (static::$show_files_inline)
					$content .= PHP_EOL.'/* '.$file['file'].' */'.PHP_EOL.PHP_EOL;
				if ($file['minified'] || !$minify)
				{
					$content_temp = static::load_file($file['file'], $type, $file_group).PHP_EOL;
					if ($type == 'css')
						$content .= Casset_Cssurirewriter::rewrite($content_temp, dirname($file['file']));
					else
						$content .= $content_temp;
				}
				else
				{
					$file_content = static::load_file($file['file'], $type, $file_group);
					if ($file_content === false)
						throw new Casset_Exception("Couldn't not open file {$file['file']}");
					if ($type == 'js')
					{
						$content .= Casset_JSMin::minify($file_content).PHP_EOL;
					}
					elseif ($type == 'css')
					{
						$css = Casset_Csscompressor::process($file_content).PHP_EOL;
						$content .= Casset_Cssurirewriter::rewrite($css, dirname($file['file']));
					}
				}
			}
			file_put_contents($filepath, $content, LOCK_EX);
			$mtime = time();
		}
		if (!$inline)
			$filename .= '?'.$mtime;
		return $filename;
	}

	/**
	 * Renders the javascript added through js_inline().
	 *
	 * @return string <script> tags containing the inline javascript
	 */
	public static function render_js_inline()
	{
		$ret = '';
		foreach (static::$inline_assets['js'] as $content)
		{
			$ret .= html_tag('script', array('type' => 'text/javascript'), PHP_EOL.$content.PHP_EOL).PHP_EOL;
		}
		return $ret;
	}

	/**
	 * Renders the css added through css_inline().
	 *
	 * @return string <style> tags containing the inline css
	 */
	public static function render_css_inline()
	{
		$ret = '';
		foreach (static::$inline_assets['css'] as $content)
		{
			$ret .= html_tag('script', array('type' => 'text/javascript'), PHP_EOL.$content.PHP_EOL).PHP_EOL;
		}
		return $ret;
	}

	/**
	 * Sets the post_load file callback. It's pretty basic, and you're expected
	 * to handle e.g. filtering for the right file yourself.
	 * @param function the function to set
	 */
	public static function set_post_load_callback($callback) {
		static::$post_load_callback = $callback;
	}

	/**
	 * Locates the given image(s), and returns the resulting <img> tag.
	 *
	 * @param mixed $images Image(s) to print. Can be string or array of strings
	 * @param string $alt The alternate text
	 * @param array $attr Attributes to apply to each image (eg width)
	 * @return string The resulting <img> tag(s)
	 */
	public static function img($images, $alt, $attr = array())
	{
		if (!is_array($images))
			$images = array($images);
		$attr['alt'] = $alt;
		$ret = '';
		foreach ($images as $image)
		{
			if (strpos($image, '::') === false)
				$image = static::$default_path_key.'::'.$image;
			$image_paths = static::find_files($image, 'img');
			foreach ($image_paths as $image_path)
			{
				$base = (strpos($image_path, '//') === false) ? static::$asset_url : '';
				$attr['src'] = $base.$image_path;
				$ret .= html_tag('img', $attr);
			}
		}
		return $ret;
	}

	/**
	 * Cleares all cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_cache($before = 'now')
	{
		static::clear_cache_base('*', $before);
	}

	/**
	 * Cleares all JS cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_js_cache($before = 'now')
	{
		static::clear_cache_base('*.js', $before);
	}

	/**
	 * Cleares CSS all cache files last modified before $before.
	 *
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	public static function clear_css_cache($before = 'now')
	{
		static::clear_cache_base('*.css', $before);
	}

	/**
	 * Base cache clear function.
	 *
	 * @param type $filter Glob filter to use when selecting files to delete.
	 * @param type $before Time before which to delete files. Defaults to 'now'.
	 *        Uses strtotime.
	 */
	private static function clear_cache_base($filter = '*', $before = 'now')
	{
		$before = strtotime($before);
		$files = glob(DOCROOT.static::$cache_path.$filter);
		foreach ($files as $file)
		{
			if (filemtime($file) < $before)
				unlink($file);
		}
	}

}

class Casset_Exception extends \Fuel_Exception {
}

/* End of file casset.php */
