<?php declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
use Dansk\Import\{EntryParser,TranslationExtractor,Text};
use Dansk\Support\Db;
$parser=new EntryParser(); $tx=new TranslationExtractor();
$rows=Db::fetchAll("SELECT raw_text FROM raw_entries WHERE status='needs_review' ORDER BY confidence DESC");
$reasons=[];
foreach($rows as $r){
  $p=$parser->parse($r['raw_text']); $t=$tx->extract($p);
  $usable=array_filter($t,fn($c)=>$c['quiz_usable']);
  if($usable) continue;
  $why = match(true){
    $p->termNote!==null && Text::hasCyrillic($p->termNote) => 'translation is in the TERM parenthetical',
    $t===[]                                                => 'no candidates at all',
    default                                                => 'all candidates filtered out',
  };
  $reasons[$why]=($reasons[$why]??0)+1;
  if(($reasons[$why]??0)<=2){
    printf("\n[%s]\n  term=%s\n  note=%s\n  head=%s\n", $why,$p->term,var_export($p->termNote,true),
      mb_substr((string)$p->headRemainder,0,70));
    foreach($t as $c) printf("     %-9s u=%d  %s\n",$c['sense_type'],$c['quiz_usable'],mb_substr($c['text'],0,60));
  }
}
echo "\n=== WHY NO ANSWER ===\n"; foreach($reasons as $k=>$v) printf("  %-42s %d\n",$k,$v);
