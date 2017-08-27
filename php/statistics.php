<?php

mb_internal_encoding("UTF-8");

include dirname(__FILE__) . '/../common.php';

if(!ini_get('date.timezone')) date_default_timezone_set('Europe/Moscow');

// получение настроек
$cfg = get_settings();

$sub=array();
$ss = "";
if(isset($_POST["ss"]) AND $_POST["ss"] <> ""){
	$ss = preg_replace("/[^0-9, ]/", "", $_POST["ss"]);
	$sub = explode(" ", $ss);
}else{
	$sub = array_keys (isset($cfg['subsections']) ? $cfg['subsections'] : array());
	$ss = implode(" ", $sub);
}

try {
	$output ="";
	if(isset($_POST["ss"])){
		$count = array(0, 0, 0, 0, 0);
		$weight = array(0.0, 0.0, 0.0, 0.0, 0.0);
	//					f.qt as CountFULL,
	//					ROUND(CAST(f.si AS FLOAT)/(1024*1024*1024), 2) as GB_FULL
		foreach($sub as $id){
			if($id <> ""){
				$data = Db::query_database(
						"SELECT
							f.na, 
							f.id,
							count(case when seed = 0 then 1 else null end) as Count0, 
							ROUND(CAST(sum(case when seed = 0 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024),2) as GB0, 
							ROUND(CAST(sum(case when seed = 0 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024*1024),2) as TB0, 
							count(case when seed > 0 and seed <= 0.5 then 1 else null end) as Count5, 
							ROUND(CAST(sum(case when seed > 0 and seed <= 0.5 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024),2) as GB5, 
							ROUND(CAST(sum(case when seed > 0 and seed <= 0.5 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024*1024),2) as TB5, 
							count(case when seed > 0.5 and seed <= 1.0 then 1 else null end) as Count10, 
							ROUND(CAST(sum(case when seed > 0.5 and seed <= 1.0 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024),2) as GB10, 
							ROUND(CAST(sum(case when seed > 0.5 and seed <= 1.0 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024*1024),2) as TB10, 
							count(case when seed > 1.0 and seed <= 1.5 then 1 else null end) as Count15,
							ROUND(CAST(sum(case when seed > 1.0 and seed <= 1.5 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024),2) as GB15, 
							ROUND(CAST(sum(case when seed > 1.0 and seed <= 1.5 then seeds.si else 0 end) AS FLOAT)/(1024*1024*1024*1024),2) as TB15,
							count(*) as CountFULL,
							ROUND(CAST(sum(seeds.si) AS FLOAT)/(1024*1024*1024), 2) as GB_FULL FROM(
								SELECT ins.*, IFNULL(CAST(ins.sum AS FLOAT)/ins.count, 0) as seed FROM (
									SELECT 
										t.id, 
										t.si,
										t.st,
										t.ss,
										(IFNULL(t.se , 0)+
										 IFNULL(s.d0 , 0)+IFNULL(s.d1 , 0)+IFNULL(s.d2 , 0)+IFNULL(s.d3 , 0)+IFNULL(s.d4 , 0)+IFNULL(s.d5 , 0)+IFNULL(s.d6 , 0)+IFNULL(s.d7 , 0)+IFNULL(s.d8 , 0)+IFNULL(s.d9 , 0)+
										 IFNULL(s.d10, 0)+IFNULL(s.d11, 0)+IFNULL(s.d12, 0)+IFNULL(s.d13, 0)+IFNULL(s.d14, 0)+IFNULL(s.d15, 0)+IFNULL(s.d16, 0)+IFNULL(s.d17, 0)+IFNULL(s.d18, 0)+IFNULL(s.d19, 0)+
										 IFNULL(s.d20, 0)+IFNULL(s.d21, 0)+IFNULL(s.d22, 0)+IFNULL(s.d23, 0)+IFNULL(s.d24, 0)+IFNULL(s.d25, 0)+IFNULL(s.d26, 0)+IFNULL(s.d27, 0)+IFNULL(s.d28, 0)+IFNULL(s.d29, 0)) as sum, 
										(IFNULL(t.qt , 0)+
										 IFNULL(s.q0 , 0)+IFNULL(s.q1 , 0)+IFNULL(s.q2 , 0)+IFNULL(s.q3 , 0)+IFNULL(s.q4 , 0)+IFNULL(s.q5 , 0)+IFNULL(s.q6 , 0)+IFNULL(s.q7 , 0)+IFNULL(s.q8 , 0)+IFNULL(s.q9 , 0)+
										 IFNULL(s.q10, 0)+IFNULL(s.q11, 0)+IFNULL(s.q12, 0)+IFNULL(s.q13, 0)+IFNULL(s.q14, 0)+IFNULL(s.q15, 0)+IFNULL(s.q16, 0)+IFNULL(s.q17, 0)+IFNULL(s.q18, 0)+IFNULL(s.q19, 0)+
										 IFNULL(s.q20, 0)+IFNULL(s.q21, 0)+IFNULL(s.q22, 0)+IFNULL(s.q23, 0)+IFNULL(s.q24, 0)+IFNULL(s.q25, 0)+IFNULL(s.q26, 0)+IFNULL(s.q27, 0)+IFNULL(s.q28, 0)+IFNULL(s.q29, 0)) as count
									FROM Topics t
									LEFT JOIN Seeders s ON t.id=s.id
									WHERE ss = $id) as ins
								ORDER BY seed) as seeds
						INNER JOIN forums f ON seeds.ss = f.id",
						array(), true);

				$dangerous_topics = intval($data[0]["Count0"])+intval($data[0]["Count5"]);
				$danger = ($dangerous_topics > 500 ? "table-danger" : ($dangerous_topics > 250 ? "table-warning" : ""));
				if(strpos($data[0]["na"], 'HD') !== false || strpos($data[0]["na"], 'DVD') !== false){
					$danger = ($dangerous_topics > 1000 ? "table-danger" : ($dangerous_topics > 500 ? "table-warning" : ""));
				}

				$output .= "<tr>
						<td class=$danger>".$data[0]["id"]."</td>
						<td class=$danger>".$data[0]["na"]."</td>
						<td style='text-align:right;'>".$data[0]["Count0"]."</td>
						<td style='text-align:right;'>".($data[0]["GB0"] < 1024 ? $data[0]["GB0"]." GB" : $data[0]["TB0"]." TB")."</td>
						<td style='text-align:right;'>".$data[0]["Count5"]."</td>
						<td style='text-align:right;'>".($data[0]["GB5"] < 1024 ? $data[0]["GB5"]." GB" : $data[0]["TB5"]." TB")."</td>
						<td style='text-align:right;'>".$data[0]["Count10"]."</td>
						<td style='text-align:right;'>".($data[0]["GB10"] < 1024 ? $data[0]["GB10"]." GB" : $data[0]["TB10"]." TB")."</td>
						<td style='text-align:right;'>".$data[0]["Count15"]."</td>
						<td style='text-align:right;'>".($data[0]["GB15"] < 1024 ? $data[0]["GB15"]." GB" : $data[0]["TB15"]." TB")."</td>
						<td style='text-align:right;'>".$data[0]["CountFULL"]."</td>
						<td style='text-align:right;'>".($data[0]["GB_FULL"] < 1024 ? $data[0]["GB_FULL"]." GB" : round($data[0]["GB_FULL"]/1024, 2)." TB")."</td>
					  </tr>";
				$count[0] = $count[0] + intval($data[0]["Count0"]);
				$count[1] = $count[1] + intval($data[0]["Count5"]);
				$count[2] = $count[2] + intval($data[0]["Count10"]);
				$count[3] = $count[3] + intval($data[0]["Count15"]);
				$count[4] = $count[4] + intval($data[0]["CountFULL"]);
				$weight[0] = $weight[0] + floatval($data[0]["GB0"]);
				$weight[1] = $weight[1] + floatval($data[0]["GB5"]);
				$weight[2] = $weight[2] + floatval($data[0]["GB10"]);
				$weight[3] = $weight[3] + floatval($data[0]["GB15"]);
				$weight[4] = $weight[4] + floatval($data[0]["GB_FULL"]);
			}
		}
		$output .= "<tr>
					<td style='border-top: 2px solid black;' colspan='2'>Всего</td>
					<td style='text-align:right;border-top: 2px solid black;'>".$count[0]."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".($weight[0] < 1024 ? $weight[0]." GB" : round($weight[0]/1024, 2)." TB")."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".$count[1]."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".($weight[1] < 1024 ? $weight[1]." GB" : round($weight[1]/1024, 2)." TB")."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".$count[2]."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".($weight[2] < 1024 ? $weight[2]." GB" : round($weight[2]/1024, 2)." TB")."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".$count[3]."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".($weight[3] < 1024 ? $weight[3]." GB" : round($weight[3]/1024, 2)." TB")."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".$count[4]."</td>
					<td style='text-align:right;border-top: 2px solid black;'>".($weight[4] < 1024 ? $weight[4]." GB" : round($weight[4]/1024, 2)." TB")."</td>
			  </tr>";
		$count[1] = $count[1] + $count[0];
		$count[2] = $count[2] + $count[1];
		$count[3] = $count[3] + $count[2];
		$count[4] = $count[4] + $count[3];
		$weight[1] = $weight[1] + $weight[0];
		$weight[2] = $weight[2] + $weight[1];
		$weight[3] = $weight[3] + $weight[2];
		$weight[4] = $weight[4] + $weight[3];
		$output .= "<tr>
					<td colspan='2'>Всего (от нуля)</td>
					<td style='text-align:right;padding-left: 20px;'>".$count[0]."</td>
					<td style='text-align:right;'>".($weight[0] < 1024 ? $weight[0]." GB" : round($weight[0]/1024, 2)." TB")."</td>
					<td style='text-align:right;padding-left: 20px;'>".$count[1]."</td>
					<td style='text-align:right;'>".($weight[1] < 1024 ? $weight[1]." GB" : round($weight[1]/1024, 2)." TB")."</td>
					<td style='text-align:right;padding-left: 20px;'>".$count[2]."</td>
					<td style='text-align:right;'>".($weight[2] < 1024 ? $weight[2]." GB" : round($weight[2]/1024, 2)." TB")."</td>
					<td style='text-align:right;padding-left: 20px;'>".$count[3]."</td>
					<td style='text-align:right;'>".($weight[3] < 1024 ? $weight[3]." GB" : round($weight[3]/1024, 2)." TB")."</td>
					<td style='text-align:right;padding-left: 20px;'>".$count[4]."</td>
					<td style='text-align:right;'>".($weight[4] < 1024 ? $weight[4]." GB" : round($weight[4]/1024, 2)." TB")."</td>
			  </tr>";
	};
	echo json_encode($output);
} catch (Exception $e) {
	echo json_encode( array('error' => $e->getMessage()) );
}
