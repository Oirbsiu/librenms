<?php
/**
 */

namespace LibreNMS\Alert;


use App\Models\AlertLog;
use Illuminate\Support\Facades\DB;

class AlertLogs
{
    public function alert_details($rule_id, $device_id,$show_links)
    {
		$query = AlertLog::query();
		$query = $query->select('details')
			->from('alert_log')
			->where('rule_id','=',$rule_id)
			->where('device_id','=',$device_id)
			->where('state','=','1')
			->orderBy('id', 'DESC')
			->limit('1')
			->get();
		$details = json_decode(gzuncompress($query[0]->details), true);
		$fault_detail = '';
		
		foreach ($details['rule'] as $o => $tmp_alerts) {
			$fallback = true;
			$fault_detail .= '#' . ($o + 1) . ':&nbsp;';
			if ($tmp_alerts['bill_id']) {
				$fault_detail .= '<a href="' . generate_bill_url($tmp_alerts) . '">' . $tmp_alerts['bill_name'] . '</a>;&nbsp;';
				if ($show_links){
					$fault_detail .= '<a href="' . generate_bill_url($tmp_alerts) . '">' . $tmp_alerts['bill_name'] . '</a>;&nbsp;';
				}else {
					$fault_detail .=  $tmp_alerts['bill_name'] . '&nbsp;';
				}
				$fallback = false;
			}

			if ($tmp_alerts['port_id']) {
				$tmp_alerts = cleanPort($tmp_alerts);
				$link = generate_port_link($tmp_alerts);
				if(!$show_links){
					$link = strip_tags($link);
				}
				$fault_detail .= $link . ';&nbsp;';
				$fallback = false;
			}

			if ($tmp_alerts['accesspoint_id']) {
				$link = generate_ap_link($tmp_alerts, $tmp_alerts['name']);
				if(!$show_links){
					$link = strip_tags($link);
				}
				$fault_detail .= $link . ';&nbsp;';
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
				$link = generate_sensor_link($tmp_alerts, $tmp_alerts['name']);
				if(!$show_links){
					$link = strip_tags($link);
				}
				$fault_detail .= $link . ';&nbsp; <br>' . $details;
				$fallback = false;
			}

			if ($tmp_alerts['bgpPeer_id']) {
				// If we have a bgpPeer_id, we format the data accordingly
				if($show_links){
					$fault_detail .= "BGP peer <a href='" .
						generate_url(['page' => 'device',
							'device' => $tmp_alerts['device_id'],
							'tab' => 'routing',
							'proto' => 'bgp', ]) .
						"'>" . $tmp_alerts['bgpPeerIdentifier'] . '</a>';
				} else {
					$fault_detail .= "BGP peer " . $tmp_alerts['bgpPeerIdentifier'];
				}
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
				if ($show_links){
					$fault_detail .= "<a href='" . generate_url(['page' => 'device',
						'device' => $tmp_alerts['device_id'],
						'tab' => 'apps',
						'app' => $tmp_alerts['app_type'], ]) . "'>";
					$fault_detail .= $tmp_alerts['metric'];
					$fault_detail .= '</a>';
				}else {
					$fault_detail .= $tmp_alerts['metric'];
				}
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
		}
		return $fault_detail;
    }
}
