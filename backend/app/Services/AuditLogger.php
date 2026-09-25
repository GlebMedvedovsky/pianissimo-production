<?php
namespace App\Services;
use App\Models\AuditEvent;
class AuditLogger
{
    public function record(string $type, ?string $id, string $action, ?string $hash=null, array $metadata=[]): void
    {
        AuditEvent::create(['user_id'=>auth()->id(),'object_type'=>$type,'object_id'=>$id,'action'=>$action,'result_hash'=>$hash,'metadata'=>$metadata?:null]);
    }
}
