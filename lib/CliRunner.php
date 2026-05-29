<?php
declare(strict_types=1);
namespace Soritune\Developers;

/** Runs a shell command, returns ['code'=>int,'out'=>string,'err'=>string].
 *  Injectable: tests pass a fake callable to constructors that take CliRunner. */
final class CliRunner
{
    /** @var callable|null */
    private $fake;

    /** @param callable|null $fake fn(string $cmd): array{code:int,out:string,err:string} */
    public function __construct(?callable $fake = null) { $this->fake = $fake; }

    public function run(string $cmd): array
    {
        if ($this->fake !== null) { return ($this->fake)($cmd); }
        $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($proc)) { return ['code'=>127,'out'=>'','err'=>'proc_open failed']; }
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code'=>$code, 'out'=>(string)$out, 'err'=>(string)$err];
    }
}
