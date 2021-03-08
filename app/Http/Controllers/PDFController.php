<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PDF;
use LibreNMS\Config;
use Illuminate\Support\Facades\Route;
use LibreNMS\Util\DynamicConfig;
use Auth;
use App\Models\AuthLog;
use App\Models\AlertLog;
use Illuminate\Support\Facades\DB;
use LibreNMS\Alert\AlertLogs;
use Illuminate\Support\Facades\App;

require '/opt/librenms/includes/init.php';
require_once '/opt/librenms/includes/html/functions.inc.php';

class PDFController extends Controller
{
	public function preview(DynamicConfig $config, $path, Request $request){
		$clean_path = \LibreNMS\Util\Clean::fileName($path);
		$view 	= 'pdf.' . $clean_path;
		
		// this is the URL used in the button "Create PDF" - probably can be done another way but this works.... 
		$route	= '/pdf/generate/'. $clean_path;

		$header_logo = \LibreNMS\Config::get('dompdf.header_logo');
		$footer_logo = \LibreNMS\Config::get('dompdf.footer_logo');
		
		// Validate that the view exists 
		if (view()->exists($view)) {
			// Run report and return JSON object - saved to $json
			include_once "/opt/librenms/includes/html/reports/$clean_path.pdf.inc.php";
			
			$data = [
				'path'      => $clean_path,
				'route'     => $route,
				'date'      => date('d/m/Y'),
				'json'      => json_decode($json),
				'header_logo' => \LibreNMS\Config::get('pdf.header_logo'),
				'header_text' => \LibreNMS\Config::get('pdf.header_text'),
				'footer_logo' => \LibreNMS\Config::get('pdf.footer_logo'),
				'owner'		  => \LibreNMS\Config::get('pdf.doc_owner'),
				'level'		  => \LibreNMS\Config::get('pdf.doc_level')
			];
			// display Preview of report using blade template
			return view($view, $data);
		} else {
			 abort(404);
		}
	}

	public function generate(DynamicConfig $config, $path){
		$clean_path = \LibreNMS\Util\Clean::fileName($path);
		$view =  'pdf.'. $clean_path;
		
		$footer_logo = \LibreNMS\Config::get('dompdf.footer_logo');
		
		// Validate that the view exists 
		if (view()->exists($view)) {
			// Run report and return JSON object - saved to $json
			include_once "/opt/librenms/includes/html/reports/$clean_path.pdf.inc.php";
			
			$data = [
				'path' 	=> $clean_path,
				'date'      => date('d/m/Y'),
				'json'      => json_decode($json),
				'header_logo' => \LibreNMS\Config::get('pdf.header_logo'),
				'header_text' => \LibreNMS\Config::get('pdf.header_text'),
				'footer_logo' => \LibreNMS\Config::get('pdf.footer_logo'),
				'owner'		  => \LibreNMS\Config::get('pdf.doc_owner'),
				'level'		  => \LibreNMS\Config::get('pdf.doc_level')
			];

			// generate PDF document from blade template and download to browser
			$download_pdf = $view . "_download"; 
			$pdf = PDF::loadView($download_pdf, $data)->setPaper('a4', 'landscape');
			$filename	= "bill-report_" .date('d-m-Y').".pdf";
			return $pdf->download($filename);
		} else {
			abort(404);
		}
	}

	public function previewAlerts(Request $request){

		$device_id 	= $request->input('device_id');
		$string		= $request->input('string'); 
		$results	= $request->input('results');
		$start		= $request->input('start');
		$report		= $request->input('report');

		if (isset($results) && is_numeric($results)) {
			$numresults = mres($results);
		} else {
			$numresults = 250;
		}
		
		// Build Base SQL Query
		$date_format = \LibreNMS\Config::get('dateformat.mysql.compact');
		$query = AlertLog::query();
		$query = $query->select('R.severity', 'D.device_id','D.sysName', 'name AS alert','rule_id','state','time_logged', DB::raw('DATE_FORMAT(time_logged, "'.$date_format.'") as humandate'))
			->from('alert_log as E')
			->leftJoin('devices as D', 'E.device_id', '=', 'D.device_id')
			->rightJoin('alert_rules as R', 'E.rule_id', '=', 'R.id');

		//json_decode(gzuncompress($id['details']), true);
		// adding by device
		if (is_numeric($device_id)) {
			$query = $query->where('E.device_id', '=', $device_id);
		}
		// adding by rule
		if ($string) {
			$query = $query->where('R.rule','LIKE', $string);
		}
		// adding based on auth
		if (! Auth::user()->hasGlobalRead()) {
			$query = $query->rightJoin('devices_perms AS P', 'E.device_id', '=', 'P.device_id');
		}

		// adding orderby
		if (! isset($sort) || empty($sort)) {
			$query = $query->orderBy('time_logged', 'DESC');
		}else {
			$query = $query->orderBy($sort);
		}

		//adding Limits and finally get results
		$query = $query->offset($start)
			->limit($numresults)
			->get();

		// probably a better solution to this but it works.....
		$json = json_decode($query->toJson());
		$show_links = false;
		foreach($json as $key => $alert) {
			$fault_detail = AlertLogs::alert_details($alert->rule_id, $alert->device_id, $show_links);
			$json[$key]->faultDetails = $fault_detail;
		}

		$data = [
			'path'			=> $clean_path,
			'date'			=> date('d/m/Y'),
			'json'			=> $json,
			'pagetitle'			=> 'Alert Logs',
			'header_logo'	=> \LibreNMS\Config::get('pdf.header_logo'),
			'header_text'	=> \LibreNMS\Config::get('pdf.header_text'),
			'footer_logo'	=> \LibreNMS\Config::get('pdf.footer_logo'),
			'owner'			=> \LibreNMS\Config::get('pdf.doc_owner'),
			'level'			=> \LibreNMS\Config::get('pdf.doc_level')
		];
		
		$returnHTML = view('pdf.alertlog')->with($data)->render();
		$pdf = PDF::loadView('pdf.alertlog', $data)->setPaper('a4', 'landscape');
		return $pdf->stream();


	}

	public function alerts(Request $request){
		$device_id 	= $request->input('device_id');
		$string		= $request->input('string'); 
		$results	= $request->input('results');
		$start		= $request->input('start');
		$report		= $request->input('report');

		if (isset($results) && is_numeric($results)) {
			$numresults = mres($results);
		} else {
			$numresults = 250;
		}
		
		// Build Base SQL Query
		$date_format = \LibreNMS\Config::get('dateformat.mysql.compact');
		$query = AlertLog::query();
		$query = $query->select('R.severity', 'D.device_id','D.sysName', 'name AS alert','rule_id','state','time_logged', DB::raw('DATE_FORMAT(time_logged, "'.$date_format.'") as humandate'))
			->from('alert_log as E')
			->leftJoin('devices as D', 'E.device_id', '=', 'D.device_id')
			->rightJoin('alert_rules as R', 'E.rule_id', '=', 'R.id');

		//json_decode(gzuncompress($id['details']), true);
		// adding by device
		if (is_numeric($device_id)) {
			$query = $query->where('E.device_id', '=', $device_id);
		}
		// adding by rule
		if ($string) {
			$query = $query->where('R.rule','LIKE', $string);
		}
		// adding based on auth
		if (! Auth::user()->hasGlobalRead()) {
			$query = $query->rightJoin('devices_perms AS P', 'E.device_id', '=', 'P.device_id');
		}

		// adding orderby
		if (! isset($sort) || empty($sort)) {
			$query = $query->orderBy('time_logged', 'DESC');
		}else {
			$query = $query->orderBy($sort);
		}

		//adding Limits and finally get results
		$query = $query->offset($start)
			->limit($numresults)
			->get();

		// probably a better solution to this but it works.....
		$json = json_decode($query->toJson());
		$show_links = false;
		foreach($json as $key => $alert) {
			$fault_detail = AlertLogs::alert_details($alert->rule_id, $alert->device_id, $show_links);
			$json[$key]->faultDetails = $fault_detail;
		}
		$data = [
			'path'			=> $clean_path,
			'date'			=> date('d/m/Y'),
			'json'			=> $json,
			'pagetitle'			=> 'Alert Logs',
			'header_logo'	=> \LibreNMS\Config::get('pdf.header_logo'),
			'header_text'	=> \LibreNMS\Config::get('pdf.header_text'),
			'footer_logo'	=> \LibreNMS\Config::get('pdf.footer_logo'),
			'owner'			=> \LibreNMS\Config::get('pdf.doc_owner'),
			'level'			=> \LibreNMS\Config::get('pdf.doc_level')
		];
		$returnHTML = view('pdf.alertlog')->with($data)->render();
		$pdf = PDF::loadView('pdf.alertlog', $data)->setPaper('a4', 'landscape');
		return $pdf->stream();


	}

	
}
