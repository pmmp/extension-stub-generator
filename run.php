<?php

try{
	$extension = new ReflectionExtension($argv[1]);
}catch(\ReflectionException $e){
	echo $e->getMessage() . PHP_EOL;
	exit(1);
}

define('TAB', "\t");

$global = [];
$namespaces = [];

$functions = $extension->getFunctions();
$classes = $extension->getClasses();
$constants = $extension->getConstants();


foreach($constants as $cname => $cvalue){
	putToNs(_constant($cname, $cvalue));
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

print doIt();

function doIt(){
	global $global, $namespaces;

	$res = '<?php' . PHP_EOL;
	$res .= '/**' . PHP_EOL . ' * Generated stub file for code completion purposes' . PHP_EOL . ' */';
	$res .= PHP_EOL . PHP_EOL;

	foreach($namespaces as $ns => $php){
		$res .= "namespace $ns {" . PHP_EOL;
		$res .= implode(PHP_EOL . PHP_EOL, $php);
		$res .= PHP_EOL . '}' . PHP_EOL;
	}
	$res .= implode(PHP_EOL . PHP_EOL, $global);

	return $res;
}

function putToNs(array $item){
	global $global, $namespaces;
	$ns = $item['ns'];
	$php = $item['php'];
	if($ns == null){
		$global[] = $php;
	}else{
		if(!isset($namespaces[$ns])){
			$namespaces[$ns] = [];
		}
		$namespaces[$ns][] = $php;
	}
}

function _constant(string $cname, $cvalue){
	$split = explode("\\", $cname);
	$name = array_pop($split);
	$namespace = null;
	if(count($split)){
		$namespace .= implode("\\", $split);
	}

	$res = 'const ' . $name . ' = ' . var_export($cvalue, true) . ';';

	return [
		'ns' => $namespace,
		'php' => $res
	];
}

function _class(ReflectionClass $c){
	$res = _classModifiers($c) . $c->getShortName();
	if($c->getParentClass()){
		$res .= " extends \\" . $c->getParentClass()->getName();
	}

	$interfaces = getActualInterfaces($c);
	if(!empty($interfaces)){
		$res .= ' implements ';

		$res .= implode(', ', array_map(function(ReflectionClass $i){
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
			if($const->getDeclaringClass() != $c){
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
		if($p->getDeclaringClass() == $c){
			$res .= _property($p);
		}
	}

	/* @var $m ReflectionMethod */
	foreach($c->getMethods() as $m){
		if($m->getDeclaringClass() == $c){
			$res .= _method($m);
		}
	}

	$res .= '}';

	return [
		'ns' => $c->inNamespace() ? $c->getNamespaceName() : null,
		'php' => $res
	];
}

function _interface(ReflectionClass $c){
	$res = _classModifiers($c) . $c->getShortName();

	$interfaces = getActualInterfaces($c);
	if(!empty($interfaces)){
		$res .= ' extends ';
		$res .= implode(', ', array_map(function(ReflectionClass $i){
			return "\\" . $i->getName();
		}, $interfaces));
	}

	$res .= '{' . PHP_EOL;

	foreach($c->getConstants() as $k => $v){
		$res .= TAB . "const " . $k . ' = ' . var_export($v, true) . ';' . PHP_EOL;
	}

	/* @var $m ReflectionMethod */
	foreach($c->getMethods() as $m){
		if($m->getDeclaringClass() == $c){
			$res .= _method($m);
		}
	}

	$res .= '}';

	return [
		'ns' => $c->inNamespace() ? $c->getNamespaceName() : null,
		'php' => $res
	];
}

function getActualInterfaces(ReflectionClass $c){
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

function _function(ReflectionFunction $f){
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


function _classModifiers(ReflectionClass $c){
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

function _property(ReflectionProperty $p){
	$res = TAB;
	if($p->getDocComment()){
		$res .= $p->getDocComment() . PHP_EOL . TAB;
	}

	$res .= _propModifiers($p) . '$' . $p->getName() . ';' . PHP_EOL;

	return $res;

}

function _propModifiers(ReflectionProperty $p){
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

function _method(ReflectionMethod $m){
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

function _methodModifiers(ReflectionMethod $m){
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

function _argument(ReflectionParameter $p){
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
			$res .= ' = ' . var_export($p->getDefaultValue(), true);
		}else{
			$res .= " = null";
		}
	}

	return $res;
}

function _type(ReflectionType $t){
	$ret = "";
	if($t->allowsNull()){
	//	$ret .= "?";
	}
	if($t->isBuiltin()){
		$ret .= "$t";
	}else{
		$ret .= "\\" . ltrim($t, "\\");
	}

	return $ret;
}
