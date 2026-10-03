<?php
namespace App\Services;
class NumberService { public static function make(string $prefix,int $id): string { return sprintf('%s-%s-%04d',$prefix,now()->format('Ymd'),$id); } }
