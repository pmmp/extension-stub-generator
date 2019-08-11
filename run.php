<?php

declare(strict_types=1);

$options = getopt('', ['ext:', 'tree']);
if(!isset($options['ext'])){
	die('Usage: php ' . $argv[0] . ' --ext=extension [--tree]
	--ext	Name of PHP extension
	--tree	Optional argument, generates a file tree for the selected extension' . PHP_EOL);
}

try{
	$extension = new ReflectionExtension($extensionName = $options['ext']);
}catch(ReflectionException $e){
	echo $e->getMessage() . PHP_EOL;
	exit(1);
}

define('TAB', "\t");
define('PHP_HEADER', "<?php\n\n");

$global = [];
$namespaces = [];

$functions = $extension->getFunctions();
$classes = $extension->getClasses();
$constants = $extension->getConstants();


foreach($constants as $constant => $value){
	putToNs(_constant($constant, $value));
}

foreach($functions as $function){
	putToNs(_function($function));
}

foreach($classes as $class){
	if($class->isInterface()){
		putToNs(_interface($class));
	}else{
		putToNs(_class($class));
	}
}

if(isset($options['tree'])){
	foreach($namespaces as $ns => $data){
		foreach($data as $filename => $php){
			@mkdir(replaceWithDirectorySeparator($ns));
			file_put_contents(replaceWithDirectorySeparator($filename), PHP_HEADER . "namespace $ns;\n\n" . implode("\n\n", $php));
		}
	}
	if(!empty($global)){
		file_put_contents("$extensionName.php", PHP_HEADER . implode("\n\n", $global));
	}
}else{
	$res = PHP_HEADER;

	foreach($namespaces as $ns => $data){
		foreach($data as $php){
			$res .= "namespace $ns {" . PHP_EOL;
			$res .= implode(PHP_EOL . PHP_EOL, $php);
			$res .= PHP_EOL . '}' . PHP_EOL . PHP_EOL;
		}
	}
	$res .= implode(PHP_EOL . PHP_EOL, $global);

	print $res;
}

function replaceWithDirectorySeparator(string $subject) : string{
	return str_replace("\\", DIRECTORY_SEPARATOR, $subject);
}

function putToNs(array $item) : void{
	global $global, $namespaces, $extensionName;
	$ns = $item['ns'];
	$php = $item['php'];
	if($ns === null){
		$global[] = $php;
	}else{
		if(!isset($namespaces[$ns])){
			$namespaces[$ns] = [];
		}
		$name = $item['name'] ?? $extensionName;
		$namespaces[$ns]["$ns\\$name.php"][] = $php;
	}
}

function _constant(string $constant, $value) : array{
	$split = explode("\\", $constant);
	$name = array_pop($split);
	$namespace = null;
	if(count($split)){
		$namespace .= implode("\\", $split);
	}

	$res = 'const ' . $name . ' = ' . var_export($value, true) . ';';

	return [
		'ns' => $namespace,
		'php' => $res
	];
}

function _class(ReflectionClass $c) : array{
	$res = _classModifiers($c) . $c->getShortName();
	if($c->getParentClass()){
		$res .= " extends \\" . $c->getParentClass()->getName();
	}

	$interfaces = getActualInterfaces($c);
	if(!empty($interfaces)){
		$res .= ' implements ';

		$res .= implode(', ', array_map(function(ReflectionClass $i) : string{
			return "\\" . $i->getName();
		}, $interfaces));
	}

	$res .= '{' . PHP_EOL;

	foreach($c->getTraits() as $t){
		$res .= TAB . "use " . $t->getName() . ';' . PHP_EOL;
	}

	foreach($c->getConstants() as $k => $v){
		$visibility = "";
		if(class_exists('ReflectionClassConstant')){
			$const = new ReflectionClassConstant($c->getName(), $k);
			if($const->getDeclaringClass()->name !== $c->name){
				continue;
			}
			if($const->isPrivate()){
				$visibility = "private";
			}elseif($const->isProtected()){
				$visibility = "protected";
			}else{
				$visibility = "public";
			}

			$visibility .= " ";
		}
		$res .= TAB . $visibility . "const " . $k . ' = ' . var_export($v, true) . ';' . PHP_EOL;
	}

	foreach($c->getProperties() as $p){
		if($p->getDeclaringClass()->name === $c->name){
			$res .= _property($p);
		}
	}

	/* @var $m ReflectionMethod */
	foreach($c->getMethods() as $m){
		if($m->getDeclaringClass()->name === $c->name){
			$res .= _method($m);
		}
	}

	$res .= '}';

	return [
		'name' => $c->getShortName(),
		'ns' => $c->inNamespace() ? $c->getNamespaceName() : null,
		'php' => $res
	];
}

function _interface(ReflectionClass $c) : array{
	$res = _classModifiers($c) . $c->getShortName();

	$interfaces = getActualInterfaces($c);
	if(!empty($interfaces)){
		$res .= ' extends ';
		$res .= implode(', ', array_map(function(ReflectionClass $i) : string{
			return "\\" . $i->getName();
		}, $interfaces));
	}

	$res .= '{' . PHP_EOL;

	foreach($c->getConstants() as $k => $v){
		$res .= TAB . "const " . $k . ' = ' . var_export($v, true) . ';' . PHP_EOL;
	}

	/* @var $m ReflectionMethod */
	foreach($c->getMethods() as $m){
		if($m->getDeclaringClass()->name === $c->name){
			$res .= _method($m);
		}
	}

	$res .= '}';

	return [
		'name' => $c->getShortName(),
		'ns' => $c->inNamespace() ? $c->getNamespaceName() : null,
		'php' => $res
	];
}

function getActualInterfaces(ReflectionClass $c) : array{
	$list = $c->getInterfaces();

	foreach($list as $interface){
		foreach(recursiveGetInterfaces($interface) as $name => $int){
			unset($list[$name]);
		}
	}

	$current = $c;
	while(($current = $current->getParentClass()) !== false){
		foreach(recursiveGetInterfaces($current) as $name => $int){
			unset($list[$name]);
		}
	}

	return $list;
}

function recursiveGetInterfaces(ReflectionClass $c) : array{
	$extra = [$list = $c->getInterfaces()];

	foreach($list as $interface){
		$extra[] = recursiveGetInterfaces($interface);
	}

	if(count($extra) > 1){
		return array_merge(...$extra);
	}elseif(!empty($extra)){
		return array_pop($extra);
	}else{
		return [];
	}
}

function _function(ReflectionFunction $f) : array{
	$res = '';
	if($f->getDocComment()){
		$res .= $f->getDocComment();
	}
	$res .= 'function ' . $f->getShortName() . '(' .
		implode(', ', array_map('_argument', $f->getParameters())) . ')';

	if($f->getReturnType()){
		$res .= ' : ' . _type($f->getReturnType());
	}

	$res .= '{}';

	return [
		'ns' => $f->inNamespace() ? $f->getNamespaceName() : null,
		'php' => $res
	];
}


function _classModifiers(ReflectionClass $c) : string{
	$res = '';
	if($c->isInterface()){
		$res .= 'interface ';
	}elseif($c->isTrait()){
		$res .= 'trait ';
	}else{
		if($c->isAbstract()){
			$res .= 'abstract ';
		}

		if($c->isFinal()){
			$res .= 'final ';
		}

		$res .= 'class ';
	}

	return $res;
}

function _property(ReflectionProperty $p) : string{
	$res = TAB;
	if($p->getDocComment()){
		$res .= $p->getDocComment() . PHP_EOL . TAB;
	}

	$res .= _propModifiers($p) . '$' . $p->getName() . ';' . PHP_EOL;

	return $res;
}

function _propModifiers(ReflectionProperty $p) : string{
	$res = '';
	if($p->isPublic()){
		$res .= 'public ';
	}
	if($p->isProtected()){
		$res .= 'protected ';
	}
	if($p->isPrivate()){
		$res .= 'private ';
	}
	if($p->isStatic()){
		$res .= 'static ';
	}

	return $res;
}

function _method(ReflectionMethod $m) : string{
	/* @var $m ReflectionMethod */
	$res = TAB;
	if($m->getDocComment()){
		$res .= $m->getDocComment() . PHP_EOL . TAB;
	}
	$res .= _methodModifiers($m) . 'function ' . $m->getName() . '(' .
		implode(', ', array_map('_argument', $m->getParameters())) . ')';

	if($m->hasReturnType()){
		$res .= ' : ' . _type($m->getReturnType());
	}

	if(!$m->isAbstract()){
		$res .= '{}' . PHP_EOL;
	}else{
		$res .= ';' . PHP_EOL;
	}

	return PHP_EOL . $res;
}

function _methodModifiers(ReflectionMethod $m) : string{
	$res = '';

	if(!$m->getDeclaringClass()->isInterface()){
		if($m->isAbstract()){
			$res .= 'abstract ';
		}

		if($m->isFinal()){
			$res .= 'final ';
		}
	}

	if($m->isPublic()){
		$res .= 'public ';
	}
	if($m->isProtected()){
		$res .= 'protected ';
	}
	if($m->isPrivate()){
		$res .= 'private ';
	}

	if($m->isStatic()){
		$res .= 'static ';
	}

	return $res;
}

function _argument(ReflectionParameter $p) : string{
	$res = '';
	if($type = $p->getType()){
		$res .= _type($type) . ' ';
	}

	if($p->isPassedByReference()){
		$res .= '&';
	}

	if($p->isVariadic()){
		$res .= '...';
	}

	$res .= '$' . $p->getName();

	if($p->isOptional() and !$p->isVariadic()){
		if($p->isDefaultValueAvailable()){
			/** @noinspection PhpUnhandledExceptionInspection */
			$res .= ' = ' . var_export($p->getDefaultValue(), true);
		}else{
			$res .= " = null";
		}
	}

	return $res;
}

function _type(ReflectionNamedType $t) : string{
	$ret = "";
	if($t->allowsNull()){
		$ret .= "?";
	}
	if($t->isBuiltin()){
		$ret .= "$t";
	}else{
		$ret .= "\\" . ltrim($t->getName(), "\\");
	}

	return $ret;
}
