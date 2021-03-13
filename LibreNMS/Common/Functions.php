<?php
/**
 * Common.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 * @copyright  2021 Mark Kneen
 * @author     Mark Kneen <mark.kneen@gmail.com>
 */

namespace LibreNMS\Common;

use LibreNMS\Config;
use LibreNMS\Enum\Alert;
use LibreNMS\Exceptions\InvalidIpException;
use LibreNMS\Util\Git;
use LibreNMS\Util\IP;
use LibreNMS\Util\Laravel;
use Symfony\Component\Process\Process;
use DateTime;
use DateTimeZone;
use DeviceCache;
use LibreNMS\Util\Rewrite;
use LibreNMS\Common\Functions;
use App\Models\Location;
use Auth;

class Functions
{
	public function zeropad($num, $length = 2)
	{
		return str_pad($num, $length, '0', STR_PAD_LEFT);
	}

	public function getDates($dayofmonth, $months = 0)
	{
		$dayofmonth = $this->zeropad($dayofmonth);
		$year = date('Y');
		$month = date('m');

		if (date('d') > $dayofmonth) {
			// Billing day is past, so it is next month
			$date_end = date_create($year . '-' . $month . '-' . $dayofmonth);
			$date_start = date_create($year . '-' . $month . '-' . $dayofmonth);
			date_add($date_end, date_interval_create_from_date_string('1 month'));
		} else {
			// Billing day will happen this month, therefore started last month
			$date_end = date_create($year . '-' . $month . '-' . $dayofmonth);
			$date_start = date_create($year . '-' . $month . '-' . $dayofmonth);
			date_sub($date_start, date_interval_create_from_date_string('1 month'));
		}

		if ($months > 0) {
			date_sub($date_start, date_interval_create_from_date_string($months . ' month'));
			date_sub($date_end, date_interval_create_from_date_string($months . ' month'));
		}

		// date_sub($date_start, date_interval_create_from_date_string('1 month'));
		date_sub($date_end, date_interval_create_from_date_string('1 day'));

		$date_from = date_format($date_start, 'Ymd') . '000000';
		$date_to = date_format($date_end, 'Ymd') . '235959';

		date_sub($date_start, date_interval_create_from_date_string('1 month'));
		date_sub($date_end, date_interval_create_from_date_string('1 month'));

		$last_from = date_format($date_start, 'Ymd') . '000000';
		$last_to = date_format($date_end, 'Ymd') . '235959';

		$return = [];
		$return['0'] = $date_from;
		$return['1'] = $date_to;
		$return['2'] = $last_from;
		$return['3'] = $last_to;

		return $return;
	}

	public function getPredictedUsage($bill_day, $cur_used)
	{
		$tmp = $this->getDates($bill_day, 0);
		$start = new DateTime($tmp[0], new DateTimeZone(date_default_timezone_get()));
		$end = new DateTime($tmp[1], new DateTimeZone(date_default_timezone_get()));
		$now = new DateTime(date('Y-m-d'), new DateTimeZone(date_default_timezone_get()));
		$total = $end->diff($start)->format('%a');
		$since = $now->diff($start)->format('%a');

		return $cur_used / $since * $total;
	}
	public function format_si($value, $round = 2, $sf = 3)
	{
		return \LibreNMS\Util\Number::formatSi($value, $round, $sf, '');
	}

	public function format_bytes_billing($value)
	{
		return $this->format_number($value, Config::get('billing.base')) . 'B';
	}//end format_bytes_billing()

	public function format_number($value, $base = 1000, $round = 2, $sf = 3)
	{
		return \LibreNMS\Util\Number::formatBase($value, $base, $round, $sf, '');
	}


	public function device_by_id_cache($device_id, $refresh = false)
	{
		$model = $refresh ? DeviceCache::refresh((int) $device_id) : DeviceCache::get((int) $device_id);

		$device = $model->toArray();
		$device['location'] = $model->location->location ?? null;
		$device['lat'] = $model->location->lat ?? null;
		$device['lng'] = $model->location->lng ?? null;
		$device['attribs'] = $model->getAttribs();
		$device['vrf_lite_cisco'] = $model->vrfLites->keyBy('context_name')->toArray();

		return $device;
	}

	public function cleanPort($interface, $device = null)
	{
		$interface['ifAlias'] = $this->display($interface['ifAlias']);
		$interface['ifName'] = $this->display($interface['ifName']);
		$interface['ifDescr'] = $this->display($interface['ifDescr']);

		if (! $device) {
			$device = $this->device_by_id_cache($interface['device_id']);
		}

		$os = strtolower($device['os']);

		if (Config::get("os.$os.ifname")) {
			$interface['label'] = $interface['ifName'];

			if ($interface['ifName'] == '') {
				$interface['label'] = $interface['ifDescr'];
			}
		} elseif (Config::get("os.$os.ifalias")) {
			$interface['label'] = $interface['ifAlias'];
		} else {
			$interface['label'] = $interface['ifDescr'];
			if (Config::get("os.$os.ifindex")) {
				$interface['label'] = $interface['label'] . ' ' . $interface['ifIndex'];
			}
		}

		if ($device['os'] == 'speedtouch') {
			[$interface['label']] = explode('thomson', $interface['label']);
		}

		if (is_array(Config::get('rewrite_if'))) {
			foreach (Config::get('rewrite_if') as $src => $val) {
				if (stristr($interface['label'], $src)) {
					$interface['label'] = $val;
				}
			}
		}

		if (is_array(Config::get('rewrite_if_regexp'))) {
			foreach (Config::get('rewrite_if_regexp') as $reg => $val) {
				if (preg_match($reg . 'i', $interface['label'])) {
					$interface['label'] = preg_replace($reg . 'i', $val, $interface['label']);
				}
			}
		}

		return $interface;
	}
	
	public function display($value, $purifier_config = [])
	{
		return \LibreNMS\Util\Clean::html($value, $purifier_config);
	}

	public function fixifName($inf)
	{
		return Rewrite::normalizeIfName($inf);
	}
	public function ifclass($ifOperStatus, $ifAdminStatus)
	{
		// fake a port model
		return \LibreNMS\Util\Url::portLinkDisplayClass((object) ['ifOperStatus' => $ifOperStatus, 'ifAdminStatus' => $ifAdminStatus]);
	}


	/**
	 * Compare $t with the value of $vars[$v], if that exists
	 * @param string $v Name of the var to test
	 * @param string $t Value to compare $vars[$v] to
	 * @return bool true, if values are the same, false if $vars[$v]
	 * is unset or values differ
	 */
	public function var_eq($v, $t)
	{
		global $vars;
		if (isset($vars[$v]) && $vars[$v] == $t) {
			return true;
		}

		return false;
	}

	/**
	 * Get the value of $vars[$v], if it exists
	 * @param string $v Name of the var to get
	 * @return string|bool The value of $vars[$v] if it exists, false if it does not exist
	 */
	public function var_get($v)
	{
		global $vars;
		if (isset($vars[$v])) {
			return $vars[$v];
		}

		return false;
	}

	public function data_uri($file, $mime)
	{
		$contents = file_get_contents($file);
		$base64 = base64_encode($contents);

		return 'data:' . $mime . ';base64,' . $base64;
	}//end data_uri()

	/**
	 * Convert string to nice case, mostly used for applications
	 *
	 * @param $item
	 * @return mixed|string
	 */
	public function nicecase($item)
	{
		return \LibreNMS\Util\StringHelpers::niceCase($item);
	}

	public function toner2colour($descr, $percent)
	{
		$colour = get_percentage_colours(100 - $percent);

		if (substr($descr, -1) == 'C' || stripos($descr, 'cyan') !== false) {
			$colour['left'] = '55D6D3';
			$colour['right'] = '33B4B1';
		}

		if (substr($descr, -1) == 'M' || stripos($descr, 'magenta') !== false) {
			$colour['left'] = 'F24AC8';
			$colour['right'] = 'D028A6';
		}

		if (substr($descr, -1) == 'Y' || stripos($descr, 'yellow') !== false
			|| stripos($descr, 'giallo') !== false
			|| stripos($descr, 'gul') !== false
		) {
			$colour['left'] = 'FFF200';
			$colour['right'] = 'DDD000';
		}

		if (substr($descr, -1) == 'K' || stripos($descr, 'black') !== false
			|| stripos($descr, 'nero') !== false
		) {
			$colour['left'] = '000000';
			$colour['right'] = '222222';
		}

		return $colour;
	}//end toner2colour()

	/**
	 * Find all links in some text and turn them into html links.
	 *
	 * @param string $text
	 * @return string
	 */
	public function linkify($text)
	{
		$regex = "#(http|https|ftp|ftps)://[a-z0-9\-.]*[a-z0-9\-]+(/\S*)?#i";

		return preg_replace($regex, '<a href="$0">$0</a>', $text);
	}

	public function generate_link($text, $vars, $new_vars = [])
	{
		return '<a href="' . generate_url($vars, $new_vars) . '">' . $text . '</a>';
	}//end generate_link()

	public function generate_url($vars, $new_vars = [])
	{
		return \LibreNMS\Util\Url::generate($vars, $new_vars);
	}

	public function escape_quotes($text)
	{
		return str_replace('"', "\'", str_replace("'", "\'", $text));
	}//end escape_quotes()

	public function generate_overlib_content($graph_array, $text)
	{
		$overlib_content = '<div class=overlib><span class=overlib-text>' . $text . '</span><br />';
		foreach (['day', 'week', 'month', 'year'] as $period) {
			$graph_array['from'] = Config::get("time.$period");
			$overlib_content .= escape_quotes($this->generate_graph_tag($graph_array));
		}

		$overlib_content .= '</div>';

		return $overlib_content;
	}//end generate_overlib_content()

	public function get_percentage_colours($percentage, $component_perc_warn = null)
	{
		return \LibreNMS\Util\Colors::percentage($percentage, $component_perc_warn);
	}//end get_percentage_colours()

	public function generate_minigraph_image($device, $start, $end, $type, $legend = 'no', $width = 275, $height = 100, $sep = '&amp;', $class = 'minigraph-image', $absolute_size = 0)
	{
		return '<img class="' . $class . '" width="' . $width . '" height="' . $height . '" src="graph.php?' . implode($sep, ['device=' . $device['device_id'], "from=$start", "to=$end", "width=$width", "height=$height", "type=$type", "legend=$legend", "absolute=$absolute_size"]) . '">';
	}//end generate_minigraph_image()

	public function generate_device_url($device, $vars = [])
	{
		return \LibreNMS\Util\Url::deviceUrl((int) $device['device_id'], $vars);
	}

	public function generate_device_link($device, $text = null, $vars = [], $start = 0, $end = 0, $escape_text = 1, $overlib = 1)
	{
		if (! $start) {
			$start = Config::get('time.day');
		}

		if (! $end) {
			$end = Config::get('time.now');
		}

		$class = devclass($device);

		$text = format_hostname($device, $text);

		$graphs = \LibreNMS\Util\Graph::getOverviewGraphsForDevice(DeviceCache::get($device['device_id']));

		$url = generate_device_url($device, $vars);

		// beginning of overlib box contains large hostname followed by hardware & OS details
		$contents = '<div><span class="list-large">' . $device['hostname'] . '</span>';
		if ($device['hardware']) {
			$contents .= ' - ' . $device['hardware'];
		}

		if ($device['os']) {
			$contents .= ' - ' . Config::getOsSetting($device['os'], 'text');
		}

		if ($device['version']) {
			$contents .= ' ' . mres($device['version']);
		}

		if ($device['features']) {
			$contents .= ' (' . mres($device['features']) . ')';
		}

		if (isset($device['location'])) {
			$contents .= ' - ' . htmlentities($device['location']);
		}

		$contents .= '</div>';

		foreach ($graphs as $entry) {
			$graph = $entry['graph'];
			$graphhead = $entry['text'];
			$contents .= '<div class="overlib-box">';
			$contents .= '<span class="overlib-title">' . $graphhead . '</span><br />';
			$contents .= generate_minigraph_image($device, $start, $end, $graph);
			$contents .= generate_minigraph_image($device, Config::get('time.week'), $end, $graph);
			$contents .= '</div>';
		}

		if ($escape_text) {
			$text = htmlentities($text);
		}

		if ($overlib == 0) {
			$link = $contents;
		} else {
			$link = overlib_link($url, $text, escape_quotes($contents), $class);
		}

		if ($this->device_permitted($device['device_id'])) {
			return $link;
		} else {
			return $device['hostname'];
		}
	}//end generate_device_link()

	public function overlib_link($url, $text, $contents, $class = null)
	{
		return \LibreNMS\Util\Url::overlibLink($url, $text, $contents, $class);
	}

	public function print_graph_popup($graph_array)
	{
		echo \LibreNMS\Util\Url::graphPopup($graph_array);
	}

	public function bill_permitted($bill_id)
	{
		if (Auth::user()->hasGlobalRead()) {
			return true;
		}

		return \Permissions::canAccessBill($bill_id, Auth::id());
	}

	public function port_permitted($port_id, $device_id = null)
	{
		if (! is_numeric($device_id)) {
			$device_id = get_device_id_by_port_id($port_id);
		}

		if ($this->device_permitted($device_id)) {
			return true;
		}

		return \Permissions::canAccessPort($port_id, Auth::id());
	}

	public function application_permitted($app_id, $device_id = null)
	{
		if (! is_numeric($app_id)) {
			return false;
		}

		if (! $device_id) {
			$device_id = get_device_id_by_app_id($app_id);
		}

		return $this->device_permitted($device_id);
	}

	public function device_permitted($device_id)
	{
		if (Auth::user() && Auth::user()->hasGlobalRead()) {
			return true;
		}

		return \Permissions::canAccessDevice($device_id, Auth::id());
	}

	public function print_graph_tag($args)
	{
		echo $this->generate_graph_tag($args);
	}//end print_graph_tag()

	public function alert_layout($severity)
	{
		switch ($severity) {
			case 'critical':
				$icon = 'exclamation';
				$color = 'danger';
				$background = 'danger';
				break;
			case 'warning':
				$icon = 'warning';
				$color = 'warning';
				$background = 'warning';
				break;
			case 'ok':
				$icon = 'check';
				$color = 'success';
				$background = 'success';
				break;
			default:
				$icon = 'info';
				$color = 'info';
				$background = 'info';
		}

		return ['icon' => $icon,
			'icon_color' => $color,
			'background_color' => $background, ];
	}

	public function generate_graph_tag($args)
	{
		return \LibreNMS\Util\Url::graphTag($args);
	}

	public function generate_lazy_graph_tag($args)
	{
		return \LibreNMS\Util\Url::lazyGraphTag($args);
	}

	public function generate_dynamic_graph_tag($args)
	{
		$urlargs = [];
		$width = 0;
		foreach ($args as $key => $arg) {
			switch (strtolower($key)) {
				case 'width':
					$width = $arg;
					$value = '{{width}}';
					break;
				case 'from':
					$value = '{{start}}';
					break;
				case 'to':
					$value = '{{end}}';
					break;
				default:
					$value = $arg;
					break;
			}
			$urlargs[] = $key . '=' . $value;
		}

		return '<img style="width:' . $width . 'px;height:100%" class="graph img-responsive" data-src-template="graph.php?' . implode('&amp;', $urlargs) . '" border="0" />';
	}//end generate_dynamic_graph_tag()

	public function generate_dynamic_graph_js($args)
	{
		$from = (is_numeric($args['from']) ? $args['from'] : '(new Date()).getTime() / 1000 - 24*3600');
		$range = (is_numeric($args['to']) ? $args['to'] - $args['from'] : '24*3600');

		$output = '<script src="js/RrdGraphJS/q-5.0.2.min.js"></script>
			<script src="js/RrdGraphJS/moment-timezone-with-data.js"></script>
			<script src="js/RrdGraphJS/rrdGraphPng.js"></script>
			  <script type="text/javascript">
				  q.ready(function(){
					  var graphs = [];
					  q(\'.graph\').forEach(function(item){
						  graphs.push(
							  q(item).rrdGraphPng({
								  canvasPadding: 120,
									initialStart: ' . $from . ',
									initialRange: ' . $range . '
							  })
						  );
					  });
				  });
				  // needed for dynamic height
				  window.onload = function(){ window.dispatchEvent(new Event(\'resize\')); }
			  </script>';

		return $output;
	}//end generate_dynamic_graph_js()

	public function generate_graph_js_state($args)
	{
		// we are going to assume we know roughly what the graph url looks like here.
		// TODO: Add sensible defaults
		$from = (is_numeric($args['from']) ? $args['from'] : 0);
		$to = (is_numeric($args['to']) ? $args['to'] : 0);
		$width = (is_numeric($args['width']) ? $args['width'] : 0);
		$height = (is_numeric($args['height']) ? $args['height'] : 0);
		$legend = str_replace("'", '', $args['legend']);

		$state = <<<STATE
	<script type="text/javascript" language="JavaScript">
	document.graphFrom = $from;
	document.graphTo = $to;
	document.graphWidth = $width;
	document.graphHeight = $height;
	document.graphLegend = '$legend';
	</script>
	STATE;

		return $state;
	}//end generate_graph_js_state()

	public function print_percentage_bar($width, $height, $percent, $left_text, $left_colour, $left_background, $right_text, $right_colour, $right_background)
	{
		$data = [
			'value'			=> $percent,
			'max'			=> min(100, $percent),	// limit size of the progress bar to 100
			'left_colour'	=> '#' .$left_colour,
			'right_colour'	=> '#' .$right_colour,
			'page_break'	=> 5
		];
		$html = view('pdf.progress', $data)->render();
		return $html;
	}

	public function generate_entity_link($type, $entity, $text = null, $graph_type = null)
	{
		global $entity_cache;

		if (is_numeric($entity)) {
			$entity = get_entity_by_id_cache($type, $entity);
		}

		switch ($type) {
			case 'port':
				$link = generate_port_link($entity, $text, $graph_type);
				break;

			case 'storage':
				if (empty($text)) {
					$text = $entity['storage_descr'];
				}

				$link = generate_link($text, ['page' => 'device', 'device' => $entity['device_id'], 'tab' => 'health', 'metric' => 'storage']);
				break;

			default:
				$link = $entity[$type . '_id'];
		}

		return $link;
	}//end generate_entity_link()

	/**
	 * Extract type and subtype from a complex graph type, also makes sure variables are file name safe.
	 * @param string $type
	 * @return array [type, subtype]
	 */
	public function extract_graph_type($type): array
	{
		preg_match('/^(?P<type>[A-Za-z0-9]+)_(?P<subtype>.+)/', $type, $graphtype);
		$type = basename($graphtype['type']);
		$subtype = basename($graphtype['subtype']);

		return [$type, $subtype];
	}

	public function generate_port_link($port, $text = null, $type = null, $overlib = 1, $single_graph = 0)
	{
		$graph_array = [];

		if (! $text) {
			$text = $this->fixifName($port['label']);
		}

		if ($type) {
			$port['graph_type'] = $type;
		}

		if (! isset($port['graph_type'])) {
			$port['graph_type'] = 'port_bits';
		}

		$class = $this->ifclass($port['ifOperStatus'], $port['ifAdminStatus']);

		if (! isset($port['hostname'])) {
			$port = array_merge($port, device_by_id_cache($port['device_id']));
		}

		$content = '<div class=list-large>' . $port['hostname'] . ' - ' . $this->fixifName(addslashes($this->display($port['label']))) . '</div>';
		if ($port['ifAlias']) {
			$content .= addslashes($this->display($port['ifAlias'])) . '<br />';
		}

		$content .= "<div style=\'width: 850px\'>";
		$graph_array['type'] = $port['graph_type'];
		$graph_array['legend'] = 'yes';
		$graph_array['height'] = '100';
		$graph_array['width'] = '340';
		$graph_array['to'] = Config::get('time.now');
		$graph_array['from'] = Config::get('time.day');
		$graph_array['id'] = $port['port_id'];
		$content .= $this->generate_graph_tag($graph_array);
		if ($single_graph == 0) {
			$graph_array['from'] = Config::get('time.week');
			$content .= $this->generate_graph_tag($graph_array);
			$graph_array['from'] = Config::get('time.month');
			$content .= $this->generate_graph_tag($graph_array);
			$graph_array['from'] = Config::get('time.year');
			$content .= $this->generate_graph_tag($graph_array);
		}

		$content .= '</div>';

		$url = $this->generate_port_url($port);

		if ($overlib == 0) {
			return $content;
		} elseif ($this->port_permitted($port['port_id'], $port['device_id'])) {
			return $this->overlib_link($url, $text, $content, $class);
		} else {
			return $this->fixifName($text);
		}
	}//end generate_port_link()

	public function generate_sensor_link($args, $text = null, $type = null)
	{
		if (! $text) {
			$text = $args['sensor_descr'];
		}

		if (! $type) {
			$args['graph_type'] = 'sensor_' . $args['sensor_class'];
		} else {
			$args['graph_type'] = 'sensor_' . $type;
		}

		if (! isset($args['hostname'])) {
			$args = array_merge($args, $this->device_by_id_cache($args['device_id']));
		}

		$content = '<div class=list-large>' . $text . '</div>';

		$content .= "<div style=\'width: 850px\'>";
		$graph_array = [
			'type' => $args['graph_type'],
			'legend' => 'yes',
			'height' => '100',
			'width' => '340',
			'to' => Config::get('time.now'),
			'from' => Config::get('time.day'),
			'id' => $args['sensor_id'],
		];
		$content .= $this->generate_graph_tag($graph_array);

		$graph_array['from'] = Config::get('time.week');
		$content .= $this->generate_graph_tag($graph_array);

		$graph_array['from'] = Config::get('time.month');
		$content .= $this->generate_graph_tag($graph_array);

		$graph_array['from'] = Config::get('time.year');
		$content .= $this->generate_graph_tag($graph_array);

		$content .= '</div>';

		$url = $this->generate_sensor_url($args);

		return $this->overlib_link($url, $text, $content, null);
	}//end generate_sensor_link()

	public function generate_sensor_url($sensor, $vars = [])
	{
		return $this->generate_url(['page' => 'graphs', 'id' => $sensor['sensor_id'], 'type' => $sensor['graph_type'], 'from' => Config::get('time.day')], $vars);
	}//end generate_sensor_url()

	public function generate_port_url($port, $vars = [])
	{
		return $this->generate_url(['page' => 'device', 'device' => $port['device_id'], 'tab' => 'port', 'port' => $port['port_id']], $vars);
	}//end generate_port_url()

	public function generate_peer_url($peer, $vars = [])
	{
		return $this->generate_url(['page' => 'device', 'device' => $peer['device_id'], 'tab' => 'routing', 'proto' => 'bgp'], $vars);
	}//end generate_peer_url()

	public function generate_bill_url($bill, $vars = [])
	{
		return $this->generate_url(['page' => 'bill', 'bill_id' => $bill['bill_id']], $vars);
	}//end generate_bill_url()

	public function generate_sap_url($sap, $vars = [])
	{
		return \LibreNMS\Util\Url::graphPopup(['device' => $sap['device_id'], 'page' => 'graphs', 'type' => 'device_sap', 'tab' => 'routing', 'proto' => 'mpls', 'view' => 'saps', 'traffic_id' => $sap['svc_oid'] . '.' . $sap['sapPortId'] . '.' . $sap['sapEncapValue']], $vars);
	}//end $this->generate_sap_url()

	public function generate_port_image($args)
	{
		if (! $args['bg']) {
			$args['bg'] = 'FFFFFF00';
		}

		return "<img src='graph.php?type=" . $args['graph_type'] . '&amp;id=' . $args['port_id'] . '&amp;from=' . $args['from'] . '&amp;to=' . $args['to'] . '&amp;width=' . $args['width'] . '&amp;height=' . $args['height'] . '&amp;bg=' . $args['bg'] . "'>";
	}//end generate_port_image()

	public function generate_port_thumbnail($port)
	{
		$port['graph_type'] = 'port_bits';
		$port['from'] = Config::get('time.day');
		$port['to'] = Config::get('time.now');
		$port['width'] = 150;
		$port['height'] = 21;

		return $this->generate_port_image($port);
	}//end generate_port_thumbnail()

	/**
	 * Create image to output text instead of a graph.
	 *
	 * @param string $text
	 * @param int[] $color
	 */
	public function graph_error($text, $color = [128, 0, 0])
	{
		global $vars, $debug;

		if (! $debug) {
			set_image_type();
		}

		$width = $vars['width'] ?? 150;
		$height = $vars['height'] ?? 60;

		if (Config::get('webui.graph_type') === 'svg') {
			$rgb = implode(', ', $color);
			$font_size = 20;
			$svg_x = 100;
			$svg_y = min($font_size, $width ? (($height / $width) * $svg_x) : 1);
			echo "<svg viewBox=\"0 0 $svg_x $svg_y\" xmlns=\"http://www.w3.org/2000/svg\"><text x=\"50%\" y=\"50%\" dominant-baseline=\"middle\" text-anchor=\"middle\" style=\"font-family: sans-serif; fill: rgb($rgb);\">$text</text></svg>";
		} else {
			$img = imagecreate($width, $height);
			imagecolorallocatealpha($img, 255, 255, 255, 127); // transparent background

			$px = ((imagesx($img) - 7.5 * strlen($text)) / 2);
			$font = $width < 200 ? 3 : 5;
			imagestring($img, $font, $px, ($height / 2 - 8), $text, imagecolorallocate($img, ...$color));

			// Output the image
			imagepng($img);
			imagedestroy($img);
		}
	}

	/**
	 * Output message to user in image format.
	 *
	 * @param string $text string to display
	 */
	public function graph_text_and_exit($text)
	{
		global $vars;

		if ($vars['showcommand'] == 'yes') {
			echo $text;

			return;
		}

		graph_error($text, [13, 21, 210]);
		exit;
	}

	public function print_port_thumbnail($args)
	{
		echo $this->generate_port_link($args, generate_port_image($args));
	}//end print_port_thumbnail()

	public function print_optionbar_start($height = 0, $width = 0, $marginbottom = 5)
	{
		echo '
			<div class="panel panel-default">
			<div class="panel-heading">
			';
	}//end print_optionbar_start()

	public function print_optionbar_end()
	{
		echo '
			</div>
			</div>
			';
	}//end print_optionbar_end()

	public function overlibprint($text)
	{
		return "onmouseover=\"return overlib('" . $text . "');\" onmouseout=\"return nd();\"";
	}//end overlibprint()

	public function humanmedia($media)
	{
		global $rewrite_iftype;
		array_preg_replace($rewrite_iftype, $media);

		return $media;
	}//end humanmedia()

	public function humanspeed($speed)
	{
		$speed = formatRates($speed);
		if ($speed == '') {
			$speed = '-';
		}

		return $speed;
	}//end humanspeed()

	public function devclass($device)
	{
		if (isset($device['status']) && $device['status'] == '0') {
			$class = 'list-device-down';
		} else {
			$class = 'list-device';
		}

		if (isset($device['disable_notify']) && $device['disable_notify'] == '1') {
			$class = 'list-device-ignored';
			if (isset($device['status']) && $device['status'] == '1') {
				$class = 'list-device-ignored-up';
			}
		}

		if (isset($device['disabled']) && $device['disabled'] == '1') {
			$class = 'list-device-disabled';
		}

		return $class;
	}//end devclass()

	public function getlocations()
	{
		$query = Location::query();
		if (Auth::user()->hasGlobalRead()) {
			$query->select('id', 'location')
					->from('locations')
					->orderBy('location');
			return $query->get();
		}
		$query->select('id', 'L.location')
				->from('devices AS D', 'locations AS L', 'devices_perms AS P')
				->where('D.device_id','=','P.device_id')
				->where('P.user_id','=',Auth::id())
				->where('D.location_id','=','L.id')
				->orderBy('location');

		return $query->get();
	}

	/**
	 * Get the recursive file size and count for a directory
	 *
	 * @param string $path
	 * @return array [size, file count]
	 */
	public function foldersize($path)
	{
		$total_size = 0;
		$total_files = 0;

		foreach (glob(rtrim($path, '/') . '/*', GLOB_NOSORT) as $item) {
			if (is_dir($item)) {
				[$folder_size, $file_count] = foldersize($item);
				$total_size += $folder_size;
				$total_files += $file_count;
			} else {
				$total_size += filesize($item);
				$total_files++;
			}
		}

		return [$total_size, $total_files];
	}

	public function generate_ap_link($args, $text = null, $type = null)
	{
		$args = cleanPort($args);
		if (! $text) {
			$text = $this->fixIfName($args['label']);
		}

		if ($type) {
			$args['graph_type'] = $type;
		}

		if (! isset($args['graph_type'])) {
			$args['graph_type'] = 'port_bits';
		}

		if (! isset($args['hostname'])) {
			$args = array_merge($args, device_by_id_cache($args['device_id']));
		}

		$content = '<div class=list-large>' . $args['text'] . ' - ' . $this->fixifName($args['label']) . '</div>';
		if ($args['ifAlias']) {
			$content .= $this->display($args['ifAlias']) . '<br />';
		}

		$content .= "<div style=\'width: 850px\'>";
		$graph_array = [];
		$graph_array['type'] = $args['graph_type'];
		$graph_array['legend'] = 'yes';
		$graph_array['height'] = '100';
		$graph_array['width'] = '340';
		$graph_array['to'] = Config::get('time.now');
		$graph_array['from'] = Config::get('time.day');
		$graph_array['id'] = $args['accesspoint_id'];
		$content .= $this->generate_graph_tag($graph_array);
		$graph_array['from'] = Config::get('time.week');
		$content .= $this->generate_graph_tag($graph_array);
		$graph_array['from'] = Config::get('time.month');
		$content .= $this->generate_graph_tag($graph_array);
		$graph_array['from'] = Config::get('time.year');
		$content .= $this->generate_graph_tag($graph_array);
		$content .= '</div>';

		$url = $this->generate_ap_url($args);
		if ($this->port_permitted($args['interface_id'], $args['device_id'])) {
			return $this->overlib_link($url, $text, $content, null);
		} else {
			return $this->fixifName($text);
		}
	}//end generate_ap_link()

	public function generate_ap_url($ap, $vars = [])
	{
		return generate_url(['page' => 'device', 'device' => $ap['device_id'], 'tab' => 'accesspoints', 'ap' => $ap['accesspoint_id']], $vars);
	}//end generate_ap_url()

	// Find all the files in the given directory that match the pattern

	public function get_matching_files($dir, $match = '/\.php$/')
	{
		$list = [];
		if ($handle = opendir($dir)) {
			while (false !== ($file = readdir($handle))) {
				if ($file != '.' && $file != '..' && preg_match($match, $file) === 1) {
					$list[] = $file;
				}
			}

			closedir($handle);
		}

		return $list;
	}//end get_matching_files()

	// Include all the files in the given directory that match the pattern

	public function include_matching_files($dir, $match = '/\.php$/')
	{
		foreach (get_matching_files($dir, $match) as $file) {
			include_once $file;
		}
	}//end include_matching_files()

	public function generate_pagination($count, $limit, $page, $links = 2)
	{
		$end_page = ceil($count / $limit);
		$start = (($page - $links) > 0) ? ($page - $links) : 1;
		$end = (($page + $links) < $end_page) ? ($page + $links) : $end_page;
		$return = '<ul class="pagination">';
		$link_class = ($page == 1) ? 'disabled' : '';
		$return .= "<li><a href='' onClick='changePage(1,event);'>&laquo;</a></li>";
		$return .= "<li class='$link_class'><a href='' onClick='changePage($page - 1,event);'>&lt;</a></li>";

		if ($start > 1) {
			$return .= "<li><a href='' onClick='changePage(1,event);'>1</a></li>";
			$return .= "<li class='disabled'><span>...</span></li>";
		}

		for ($x = $start; $x <= $end; $x++) {
			$link_class = ($page == $x) ? 'active' : '';
			$return .= "<li class='$link_class'><a href='' onClick='changePage($x,event);'>$x </a></li>";
		}

		if ($end < $end_page) {
			$return .= "<li class='disabled'><span>...</span></li>";
			$return .= "<li><a href='' onClick='changePage($end_page,event);'>$end_page</a></li>";
		}

		$link_class = ($page == $end_page) ? 'disabled' : '';
		$return .= "<li class='$link_class'><a href='' onClick='changePage($page + 1,event);'>&gt;</a></li>";
		$return .= "<li class='$link_class'><a href='' onClick='changePage($end_page,event);'>&raquo;</a></li>";
		$return .= '</ul>';

		return $return;
	}//end generate_pagination()

	public function demo_account()
	{
		print_error("You are logged in as a demo account, this page isn't accessible to you");
	}//end demo_account()

	public function get_client_ip()
	{
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$client_ip = $_SERVER['REMOTE_ADDR'];
		}

		return $client_ip;
	}//end get_client_ip()

	/**
	 * @param $string
	 * @param int $max
	 * @return string
	 */
	public function shorten_text($string, $max = 30)
	{
		return \LibreNMS\Util\StringHelpers::shortenText($string, $max);
	}

	public function shorten_interface_type($string)
	{
		return str_ireplace(
			[
				'FastEthernet',
				'TenGigabitEthernet',
				'GigabitEthernet',
				'Port-Channel',
				'Ethernet',
				'Bundle-Ether',
			],
			[
				'Fa',
				'Te',
				'Gi',
				'Po',
				'Eth',
				'BE',
			],
			$string
		);
	}//end shorten_interface_type()

	public function clean_bootgrid($string)
	{
		$output = str_replace(["\r", "\n"], '', $string);
		$output = addslashes($output);

		return $output;
	}//end clean_bootgrid()

	public function get_url()
	{
		// http://stackoverflow.com/questions/2820723/how-to-get-base-url-with-php
		// http://stackoverflow.com/users/184600/ma%C4%8Dek
		return sprintf(
			'%s://%s%s',
			isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
			$_SERVER['SERVER_NAME'],
			$_SERVER['REQUEST_URI']
		);
	}//end get_url()

	public function alert_details($details)
	{
		if (! is_array($details)) {
			$details = json_decode(gzuncompress($details), true);
		}

		$fault_detail = '';
		foreach ($details['rule'] as $o => $tmp_alerts) {
			$fallback = true;
			$fault_detail .= '#' . ($o + 1) . ':&nbsp;';
			if ($tmp_alerts['bill_id']) {
				$fault_detail .= '<a href="' . generate_bill_url($tmp_alerts) . '">' . $tmp_alerts['bill_name'] . '</a>;&nbsp;';
				$fallback = false;
			}

			if ($tmp_alerts['port_id']) {
				$tmp_alerts = $this->cleanPort($tmp_alerts);
				$fault_detail .= $this->generate_port_link($tmp_alerts) . ';&nbsp;';
				$fallback = false;
			}

			if ($tmp_alerts['accesspoint_id']) {
				$fault_detail .= $this->generate_ap_link($tmp_alerts, $tmp_alerts['name']) . ';&nbsp;';
				$fallback = false;
			}

			if ($tmp_alerts['sensor_id']) {
				if ($tmp_alerts['sensor_class'] == 'state') {
					// Give more details for a state (textual form)
					$details = 'State: ' . $tmp_alerts['state_descr'] . ' (numerical ' . $tmp_alerts['sensor_current'] . ')<br>  ';
				} else {
					// Other sensors
					$details = 'Value: ' . $tmp_alerts['sensor_current'] . ' (' . $tmp_alerts['sensor_class'] . ')<br>  ';
				}
				$details_a = [];

				if ($tmp_alerts['sensor_limit_low']) {
					$details_a[] = 'low: ' . $tmp_alerts['sensor_limit_low'];
				}
				if ($tmp_alerts['sensor_limit_low_warn']) {
					$details_a[] = 'low_warn: ' . $tmp_alerts['sensor_limit_low_warn'];
				}
				if ($tmp_alerts['sensor_limit_warn']) {
					$details_a[] = 'high_warn: ' . $tmp_alerts['sensor_limit_warn'];
				}
				if ($tmp_alerts['sensor_limit']) {
					$details_a[] = 'high: ' . $tmp_alerts['sensor_limit'];
				}
				$details .= implode(', ', $details_a);

				$fault_detail .= generate_sensor_link($tmp_alerts, $tmp_alerts['name']) . ';&nbsp; <br>' . $details;
				$fallback = false;
			}

			if ($tmp_alerts['bgpPeer_id']) {
				// If we have a bgpPeer_id, we format the data accordingly
				$fault_detail .= "BGP peer <a href='" .
					$this->generate_url(['page' => 'device',
						'device' => $tmp_alerts['device_id'],
						'tab' => 'routing',
						'proto' => 'bgp', ]) .
					"'>" . $tmp_alerts['bgpPeerIdentifier'] . '</a>';
				$fault_detail .= ', AS' . $tmp_alerts['bgpPeerRemoteAs'];
				$fault_detail .= ', State ' . $tmp_alerts['bgpPeerState'];
				$fallback = false;
			}

			if ($tmp_alerts['type'] && $tmp_alerts['label']) {
				if ($tmp_alerts['error'] == '') {
					$fault_detail .= ' ' . $tmp_alerts['type'] . ' - ' . $tmp_alerts['label'] . ';&nbsp;';
				} else {
					$fault_detail .= ' ' . $tmp_alerts['type'] . ' - ' . $tmp_alerts['label'] . ' - ' . $tmp_alerts['error'] . ';&nbsp;';
				}
				$fallback = false;
			}

			if (in_array('app_id', array_keys($tmp_alerts))) {
				$fault_detail .= "<a href='" . generate_url(['page' => 'device',
					'device' => $tmp_alerts['device_id'],
					'tab' => 'apps',
					'app' => $tmp_alerts['app_type'], ]) . "'>";
				$fault_detail .= $tmp_alerts['metric'];
				$fault_detail .= '</a>';

				$fault_detail .= ' => ' . $tmp_alerts['value'];
				$fallback = false;
			}

			if ($fallback === true) {
				$fault_detail_data = [];
				foreach ($tmp_alerts as $k => $v) {
					if (in_array($k, ['device_id', 'sysObjectID', 'sysDescr', 'location_id'])) {
						continue;
					}
					if (! empty($v) && str_i_contains($k, ['id', 'desc', 'msg', 'last'])) {
						$fault_detail_data[] = "$k => '$v'";
					}
				}
				$fault_detail .= count($fault_detail_data) ? implode('<br>&nbsp;&nbsp;&nbsp', $fault_detail_data) : '';

				$fault_detail = rtrim($fault_detail, ', ');
			}

			$fault_detail .= '<br>';
		}//end foreach

		return $fault_detail;
	}//end alert_details()

	public function dynamic_override_config($type, $name, $device)
	{
		$attrib_val = get_dev_attrib($device, $name);
		if ($attrib_val == 'true') {
			$checked = 'checked';
		} else {
			$checked = '';
		}
		if ($type == 'checkbox') {
			return '<input type="checkbox" id="override_config" name="override_config" data-attrib="' . $name . '" data-device_id="' . $device['device_id'] . '" data-size="small" ' . $checked . '>';
		} elseif ($type == 'text') {
			return '<input type="text" id="override_config_text" name="override_config_text" data-attrib="' . $name . '" data-device_id="' . $device['device_id'] . '" value="' . $attrib_val . '">';
		}
	}//end dynamic_override_config()

	/**
	 * Return the rows from 'ports' for all ports of a certain type as parsed by port_descr_parser.
	 * One or an array of strings can be provided as an argument; if an array is passed, all ports matching
	 * any of the types in the array are returned.
	 * @param $types mixed String or strings matching 'port_descr_type's.
	 * @return array Rows from the ports table for matching ports.
	 */
	public function get_ports_from_type($given_types)
	{
		// Make the arg an array if it isn't, so subsequent steps only have to handle arrays.
		if (! is_array($given_types)) {
			$given_types = [$given_types];
		}

		// Check the config for a '_descr' entry for each argument. This is how a 'custom_descr' entry can
		//  be key/valued to some other string that's actually searched for in the DB. Merge or append the
		//  configured value if it's an array or a string. Or append the argument itself if there's no matching
		//  entry in config.
		$search_types = [];
		foreach ($given_types as $type) {
			if (Config::has($type . '_descr')) {
				$type_descr = Config::get($type . '_descr');
				if (is_array($type_descr)) {
					$search_types = array_merge($search_types, $type_descr);
				} else {
					$search_types[] = $type_descr;
				}
			} else {
				$search_types[] = $type;
			}
		}

		// Using the full list of strings to search the DB for, build the 'where' portion of a query that
		//  compares 'port_descr_type' with entry in the list. Also, since '@' is the convential wildcard,
		//  replace it with '%' so it functions as a wildcard in the SQL query.
		$type_where = ' (';
		$or = '';
		$type_param = [];

		foreach ($search_types as $type) {
			if (! empty($type)) {
				$type = strtr($type, '@', '%');
				$type_where .= " $or `port_descr_type` LIKE ?";
				$or = 'OR';
				$type_param[] = $type;
			}
		}
		$type_where .= ') ';

		// Run the query with the generated 'where' and necessary parameters, and send it back.
		$ports = dbFetchRows("SELECT * FROM `ports` as I, `devices` AS D WHERE $type_where AND I.device_id = D.device_id ORDER BY I.ifAlias", $type_param);

		return $ports;
	}

	/**
	 * @param $filename
	 * @param $content
	 */
	public function file_download($filename, $content)
	{
		$length = strlen($content);
		header('Content-Description: File Transfer');
		header('Content-Type: text/plain');
		header("Content-Disposition: attachment; filename=$filename");
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $length);
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Expires: 0');
		header('Pragma: public');
		echo $content;
	}

	public function get_rules_from_json()
	{
		return json_decode(file_get_contents(Config::get('install_dir') . '/misc/alert_rules.json'), true);
	}

	public function search_oxidized_config($search_in_conf_textbox)
	{
		if (! Auth::user()->hasGlobalRead()) {
			return false;
		}

		$oxidized_search_url = Config::get('oxidized.url') . '/nodes/conf_search?format=json';
		$postdata = http_build_query(
			[
				'search_in_conf_textbox' => $search_in_conf_textbox,
			]
		);
		$opts = ['http' => [
			'method' => 'POST',
			'header' => 'Content-type: application/x-www-form-urlencoded',
			'content' => $postdata,
		],
		];
		$context = stream_context_create($opts);

		$nodes = json_decode(file_get_contents($oxidized_search_url, false, $context), true);
		// Look up Oxidized node names to LibreNMS devices for a link
		foreach ($nodes as &$n) {
			$dev = device_by_name($n['node']);
			$n['dev_id'] = $dev ? $dev['device_id'] : false;
		}

		/*
		// Filter nodes we don't have access too
		$nodes = array_filter($nodes, function($device) {
			return \Permissions::canAccessDevice($device['dev_id'], Auth::id());
		});
		*/

		return $nodes;
	}

	/**
	 * @param $data
	 * @return bool|mixed
	 */
	public function array_to_htmljson($data)
	{
		if (is_array($data)) {
			$data = htmlentities(json_encode($data));

			return str_replace(',', ',<br />', $data);
		} else {
			return false;
		}
	}

	/**
	 * @param int $eventlog_severity
	 * @return string $eventlog_severity_icon
	 */
	public function eventlog_severity($eventlog_severity)
	{
		switch ($eventlog_severity) {
			case 1:
				return 'label-success'; //OK
			case 2:
				return 'label-info'; //Informational
			case 3:
				return 'label-primary'; //Notice
			case 4:
				return 'label-warning'; //Warning
			case 5:
				return 'label-danger'; //Critical
			default:
				return 'label-default'; //Unknown
		}
	} // end eventlog_severity

	public function set_image_type()
	{
		return header('Content-type: ' . get_image_type());
	}

	public function get_image_type()
	{
		if (Config::get('webui.graph_type') === 'svg') {
			return 'image/svg+xml';
		} else {
			return 'image/png';
		}
	}

	public function get_oxidized_nodes_list()
	{
		$context = stream_context_create([
			'http' => [
				'header' => 'Accept: application/json',
			],
		]);

		$data = json_decode(file_get_contents(Config::get('oxidized.url') . '/nodes?format=json', false, $context), true);

		foreach ($data as $object) {
			$device = device_by_name($object['name']);
			if (! $this->device_permitted($device['device_id'])) {
				//user cannot see this device, so let's skip it.
				continue;
			}

			echo '<tr>
			<td>' . $device['device_id'] . '</td>
			<td>' . $object['name'] . '</td>
			<td>' . $device['sysName'] . '</td>
			<td>' . $object['status'] . '</td>
			<td>' . $object['time'] . '</td>
			<td>' . $object['model'] . '</td>
			<td>' . $object['group'] . '</td>
			<td></td>
			</tr>';
		}
	}

	// fetches disks for a system
	public function get_disks($device)
	{
		return dbFetchRows('SELECT * FROM `ucd_diskio` WHERE device_id = ? ORDER BY diskio_descr', [$device]);
	}

	/**
	 * Get the fail2ban jails for a device... just requires the device ID
	 * an empty return means either no jails or fail2ban is not in use
	 * @param $device_id
	 * @return array
	 */
	public function get_fail2ban_jails($device_id)
	{
		$options = [
			'filter' => [
				'type' => ['=', 'fail2ban'],
			],
		];

		$component = new LibreNMS\Component();
		$f2bc = $component->getComponents($device_id, $options);

		if (isset($f2bc[$device_id])) {
			$id = $component->getFirstComponentID($f2bc, $device_id);

			return json_decode($f2bc[$device_id][$id]['jails']);
		}

		return [];
	}

	/**
	 * Get the Postgres databases for a device... just requires the device ID
	 * an empty return means Postres is not in use
	 * @param $device_id
	 * @return array
	 */
	public function get_postgres_databases($device_id)
	{
		$options = [
			'filter' => [
				'type' => ['=', 'postgres'],
			],
		];

		$component = new LibreNMS\Component();
		$pgc = $component->getComponents($device_id, $options);

		if (isset($pgc[$device_id])) {
			$id = $component->getFirstComponentID($pgc, $device_id);

			return json_decode($pgc[$device_id][$id]['databases']);
		}

		return [];
	}

	/**
	 * Get all application data from the collected
	 * rrd files.
	 *
	 * @param array $device device for which we get the rrd's
	 * @param int   $app_id application id on the device
	 * @param string  $category which category of graphs are searched
	 * @return array list of entry data
	 */
	public function get_arrays_with_application($device, $app_id, $app_name, $category = null)
	{
		$entries = [];
		$separator = '-';

		if ($category) {
			$pattern = sprintf('%s/%s-%s-%s-%s-*.rrd', get_rrd_dir($device['hostname']), 'app', $app_name, $app_id, $category);
		} else {
			$pattern = sprintf('%s/%s-%s-%s-*.rrd', get_rrd_dir($device['hostname']), 'app', $app_name, $app_id);
		}

		// app_name contains a separator character? consider it
		$offset = substr_count($app_name, $separator);

		foreach (glob($pattern) as $rrd) {
			$filename = basename($rrd, '.rrd');

			$entry = explode($separator, $filename, 4 + $offset)[3 + $offset];

			if ($entry) {
				array_push($entries, $entry);
			}
		}

		return $entries;
	}

	/**
	 * Return stacked graphs information
	 *
	 * @param string $transparency value of desired transparency applied to rrdtool options (values 01 - 99)
	 * @return array containing transparency and stacked setup
	 */
	public function generate_stacked_graphs($transparency = '88')
	{
		if (Config::get('webui.graph_stacked') == true) {
			return ['transparency' => $transparency, 'stacked' => '1'];
		} else {
			return ['transparency' => '', 'stacked' => '-1'];
		}
	}

	/**
	 * Parse AT time spec, does not handle the entire spec.
	 * @param string $time
	 * @return int
	 */
	public function parse_at_time($time)
	{
		if (is_numeric($time)) {
			return $time < 0 ? time() + $time : intval($time);
		}

		if (preg_match('/^[+-]\d+[hdmy]$/', $time)) {
			$units = [
				'm' => 60,
				'h' => 3600,
				'd' => 86400,
				'y' => 31557600,
			];
			$value = substr($time, 1, -1);
			$unit = substr($time, -1);

			$offset = ($time[0] == '-' ? -1 : 1) * $units[$unit] * $value;

			return time() + $offset;
		}

		return (int) strtotime($time);
	}

	/**
	 * Get the ZFS pools for a device... just requires the device ID
	 * an empty return means ZFS is not in use or there are currently no pools
	 * @param $device_id
	 * @return array
	 */
	public function get_zfs_pools($device_id)
	{
		$options = [
			'filter' => [
				'type' => ['=', 'zfs'],
			],
		];

		$component = new LibreNMS\Component();
		$zfsc = $component->getComponents($device_id, $options);

		if (isset($zfsc[$device_id])) {
			$id = $component->getFirstComponentID($zfsc, $device_id);

			return json_decode($zfsc[$device_id][$id]['pools']);
		}

		return [];
	}

	/**
	 * Get the ports for a device... just requires the device ID
	 * an empty return means portsactivity is not in use or there are currently no ports
	 * @param $device_id
	 * @return array
	 */
	public function get_portactivity_ports($device_id)
	{
		$options = [
			'filter' => [
				'type' => ['=', 'portsactivity'],
			],
		];

		$component = new LibreNMS\Component();
		$portsc = $component->getComponents($device_id, $options);

		if (isset($portsc[$device_id])) {
			$id = $component->getFirstComponentID($portsc, $device_id);

			return json_decode($portsc[$device_id][$id]['ports']);
		}

		return [];
	}

	/**
	 * Returns the sysname of a device with a html line break prepended.
	 * if the device has an empty sysname it will return device's hostname instead
	 * And finally if the device has no hostname it will return an empty string
	 * @param array device
	 * @return string
	 */
	public function get_device_name($device)
	{
		$ret_str = '';

		if (format_hostname($device) !== $device['sysName']) {
			$ret_str = $device['sysName'];
		} elseif ($device['hostname'] !== $device['ip']) {
			$ret_str = $device['hostname'];
		}

		return $ret_str;
	}

	/**
	 * Returns state generic label from value with optional text
	 */
	public function get_state_label($sensor)
	{
		$state_translation = dbFetchRow('SELECT * FROM state_translations as ST, sensors_to_state_indexes as SSI WHERE ST.state_index_id=SSI.state_index_id AND SSI.sensor_id = ? AND ST.state_value = ? ', [$sensor['sensor_id'], $sensor['sensor_current']]);

		switch ($state_translation['state_generic_value']) {
			case 0:  // OK
				$state_text = $state_translation['state_descr'] ?: 'OK';
				$state_label = 'label-success';
				break;
			case 1:  // Warning
				$state_text = $state_translation['state_descr'] ?: 'Warning';
				$state_label = 'label-warning';
				break;
			case 2:  // Critical
				$state_text = $state_translation['state_descr'] ?: 'Critical';
				$state_label = 'label-danger';
				break;
			case 3:  // Unknown
			default:
				$state_text = $state_translation['state_descr'] ?: 'Unknown';
				$state_label = 'label-default';
		}

		return "<span class='label $state_label'>$state_text</span>";
	}

	/**
	 * Get sensor label and state color
	 * @param array $sensor
	 * @param string $type sensors or wireless
	 * @return string
	 */
	public function get_sensor_label_color($sensor, $type = 'sensors')
	{
		$label_style = 'label-success';
		if (is_null($sensor)) {
			return 'label-unknown';
		}
		if (! is_null($sensor['sensor_limit_warn']) && $sensor['sensor_current'] > $sensor['sensor_limit_warn']) {
			$label_style = 'label-warning';
		}
		if (! is_null($sensor['sensor_limit_low_warn']) && $sensor['sensor_current'] < $sensor['sensor_limit_low_warn']) {
			$label_style = 'label-warning';
		}
		if (! is_null($sensor['sensor_limit']) && $sensor['sensor_current'] > $sensor['sensor_limit']) {
			$label_style = 'label-danger';
		}
		if (! is_null($sensor['sensor_limit_low']) && $sensor['sensor_current'] < $sensor['sensor_limit_low']) {
			$label_style = 'label-danger';
		}
		$unit = __("$type.{$sensor['sensor_class']}.unit");
		if ($sensor['sensor_class'] == 'runtime') {
			$sensor['sensor_current'] = formatUptime($sensor['sensor_current'] * 60, 'short');

			return "<span class='label $label_style'>" . trim($sensor['sensor_current']) . '</span>';
		}
		if ($sensor['sensor_class'] == 'frequency' && $sensor['sensor_type'] == 'openwrt') {
			return "<span class='label $label_style'>" . trim($sensor['sensor_current']) . ' ' . $unit . '</span>';
		}

		return "<span class='label $label_style'>" . trim(format_si($sensor['sensor_current']) . $unit) . '</span>';
	}

	/**
	 * @params int unix time
	 * @params int seconds
	 * @return int
	 *
	 * Rounds down to the nearest interval.
	 *
	 * The first argument is required and it is the unix time being
	 * rounded down.
	 *
	 * The second value is the time interval. If not specified, it
	 * defaults to 300, or 5 minutes.
	 */
	public function lowest_time($time, $seconds = 300)
	{
		return $time - ($time % $seconds);
	}

	/**
	 * @params int
	 * @return string
	 *
	 * This returns the subpath for working with nfdump.
	 *
	 * 1 value is taken and that is a unix time stamp. It will be then be rounded
	 * off to the lowest five minutes earlier.
	 *
	 * The return string will be a path partial you can use with nfdump to tell it what
	 * file or range of files to use.
	 *
	 * Below ie a explanation of the layouts as taken from the NfSen config file.
	 *  0             no hierachy levels - flat layout - compatible with pre NfSen version
	 *  1 %Y/%m/%d    year/month/day
	 *  2 %Y/%m/%d/%H year/month/day/hour
	 *  3 %Y/%W/%u    year/week_of_year/day_of_week
	 *  4 %Y/%W/%u/%H year/week_of_year/day_of_week/hour
	 *  5 %Y/%j       year/day-of-year
	 *  6 %Y/%j/%H    year/day-of-year/hour
	 *  7 %Y-%m-%d    year-month-day
	 *  8 %Y-%m-%d/%H year-month-day/hour
	 */
	public function time_to_nfsen_subpath($time)
	{
		$time = lowest_time($time);
		$layout = Config::get('nfsen_subdirlayout');

		if ($layout == 0) {
			return 'nfcapd.' . date('YmdHi', $time);
		} elseif ($layout == 1) {
			return date('Y\/m\/d\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 2) {
			return date('Y\/m\/d\/H\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 3) {
			return date('Y\/W\/w\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 4) {
			return date('Y\/W\/w\/H\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 5) {
			return date('Y\/z\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 6) {
			return date('Y\/z\/H\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 7) {
			return date('Y\-m\-d\/\n\f\c\a\p\d\.YmdHi', $time);
		} elseif ($layout == 8) {
			return date('Y\-m\-d\/H\/\n\f\c\a\p\d\.YmdHi', $time);
		}
	}

	/**
	 * @params string hostname
	 * @return string
	 *
	 * Takes a hostname and transforms it to the name
	 * used by nfsen.
	 */
	public function nfsen_hostname($hostname)
	{
		$nfsen_hostname = str_replace('.', Config::get('nfsen_split_char'), $hostname);

		if (! is_null(Config::get('nfsen_suffix'))) {
			$nfsen_hostname = str_replace(Config::get('nfsen_suffix'), '', $nfsen_hostname);
		}

		return $nfsen_hostname;
	}

	/**
	 * @params string hostname
	 * @return string
	 *
	 * Takes a hostname and returns the path to the nfsen
	 * live dir.
	 */
	public function nfsen_live_dir($hostname)
	{
		$hostname = $this->nfsen_hostname($hostname);

		foreach (Config::get('nfsen_base') as $base_dir) {
			if (file_exists($base_dir) && is_dir($base_dir)) {
				return $base_dir . '/profiles-data/live/' . $hostname;
			}
		}
	}
	
}