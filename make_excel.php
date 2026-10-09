<?php
$active=[];
foreach(glob("docs/v3/2026/*.json") as $f){
    $bn=basename($f);
    if($bn<"20260408.json" || $bn>"20261004.json") continue;
    $d=json_decode(file_get_contents($f),true);
    foreach(($d["results"]??[]) as $r)
        foreach(($r["boats"]??[]) as $b)
            if(isset($b["racer_number"])) $active[(string)$b["racer_number"]]=1;
}
$s=[];
foreach([2024,2025,2026] as $y){
    foreach(glob("docs/v3/$y/*.json") as $f){
        if($y==2026 && basename($f)>"20261004.json") continue;
        $d=json_decode(file_get_contents($f),true);
        foreach(($d["results"]??[]) as $r){
            $b2=null;
            foreach(($r["boats"]??[]) as $b){
                if((int)($b["racer_boat_number"]??0)==2 && (int)($b["racer_course_number"]??0)==2){
                    $b2=$b; break;
                }
            }
            if(!$b2) continue;
            $no=(string)$b2["racer_number"];
            if(!isset($s[$no])) $s[$no]=[
                "name"=>$b2["racer_name"]??"","n"=>0,"escape"=>0,
                "win"=>0,"second"=>0,"tech"=>array_fill(1,6,0)
            ];
            $s[$no]["n"]++;
            $place=(int)($b2["racer_place_number"]??99);
            $tech=(int)($r["technique_number"]??0);
            if($tech==1) $s[$no]["escape"]++;
            if($place==1){
                $s[$no]["win"]++;
                if($tech>=1 && $tech<=6) $s[$no]["tech"][$tech]++;
            }
            if($place==2) $s[$no]["second"]++;
        }
    }
}
$o=fopen("boat_racer_complete.csv","w");
fputcsv($o,[
"登録番号","選手名","2号艇2コース走数",
"1号艇逃げ回数","逃し率(%)",
"本人1着回数","本人1着率(%)",
"本人2着回数","本人2着率(%)","2連対率(%)",
"差し回数","差し率(%)",
"まくり回数","まくり率(%)",
"まくり差し回数","まくり差し率(%)",
"抜き回数","抜き率(%)",
"恵まれ回数","恵まれ率(%)"
]);
foreach($s as $no=>$v){
    if($v["n"]<30 || !isset($active[$no])) continue;
    $n=$v["n"]; $p=fn($x)=>round(100*$x/$n,2);
    fputcsv($o,[
        $no,$v["name"],$n,
        $v["escape"],$p($v["escape"]),
        $v["win"],$p($v["win"]),
        $v["second"],$p($v["second"]),$p($v["win"]+$v["second"]),
        $v["tech"][2],$p($v["tech"][2]),
        $v["tech"][3],$p($v["tech"][3]),
        $v["tech"][4],$p($v["tech"][4]),
        $v["tech"][5],$p($v["tech"][5]),
        $v["tech"][6],$p($v["tech"][6])
    ]);
}
fclose($o);
echo "CSV完成\n";
?>
