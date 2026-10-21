<?php
// Autoloader for the vendored dependency set (see README: "Dependencies").
// Running `composer install` replaces this file with Composer's generated autoloader.
declare(strict_types=1);

$__wikiVendor = __DIR__;
$__wikiClassmap = array (
  'Nette\\ArgumentOutOfRangeException' => 'nette/utils/src/exceptions.php',
  'Nette\\DeprecatedException' => 'nette/utils/src/exceptions.php',
  'Nette\\DirectoryNotFoundException' => 'nette/utils/src/exceptions.php',
  'Nette\\FileNotFoundException' => 'nette/utils/src/exceptions.php',
  'Nette\\HtmlStringable' => 'nette/utils/src/HtmlStringable.php',
  'Nette\\IOException' => 'nette/utils/src/exceptions.php',
  'Nette\\InvalidArgumentException' => 'nette/utils/src/exceptions.php',
  'Nette\\InvalidStateException' => 'nette/utils/src/exceptions.php',
  'Nette\\Iterators\\CachingIterator' => 'nette/utils/src/Iterators/CachingIterator.php',
  'Nette\\Iterators\\Mapper' => 'nette/utils/src/Iterators/Mapper.php',
  'Nette\\Localization\\Translator' => 'nette/utils/src/Translator.php',
  'Nette\\MemberAccessException' => 'nette/utils/src/exceptions.php',
  'Nette\\NotImplementedException' => 'nette/utils/src/exceptions.php',
  'Nette\\NotSupportedException' => 'nette/utils/src/exceptions.php',
  'Nette\\OutOfRangeException' => 'nette/utils/src/exceptions.php',
  'Nette\\Schema\\Context' => 'nette/schema/src/Schema/Context.php',
  'Nette\\Schema\\DynamicParameter' => 'nette/schema/src/Schema/DynamicParameter.php',
  'Nette\\Schema\\Elements\\AnyOf' => 'nette/schema/src/Schema/Elements/AnyOf.php',
  'Nette\\Schema\\Elements\\Base' => 'nette/schema/src/Schema/Elements/Base.php',
  'Nette\\Schema\\Elements\\Structure' => 'nette/schema/src/Schema/Elements/Structure.php',
  'Nette\\Schema\\Elements\\Type' => 'nette/schema/src/Schema/Elements/Type.php',
  'Nette\\Schema\\Expect' => 'nette/schema/src/Schema/Expect.php',
  'Nette\\Schema\\Helpers' => 'nette/schema/src/Schema/Helpers.php',
  'Nette\\Schema\\Message' => 'nette/schema/src/Schema/Message.php',
  'Nette\\Schema\\Processor' => 'nette/schema/src/Schema/Processor.php',
  'Nette\\Schema\\Schema' => 'nette/schema/src/Schema/Schema.php',
  'Nette\\Schema\\ValidationException' => 'nette/schema/src/Schema/ValidationException.php',
  'Nette\\SmartObject' => 'nette/utils/src/SmartObject.php',
  'Nette\\StaticClass' => 'nette/utils/src/StaticClass.php',
  'Nette\\UnexpectedValueException' => 'nette/utils/src/exceptions.php',
  'Nette\\Utils\\ArrayHash' => 'nette/utils/src/Utils/ArrayHash.php',
  'Nette\\Utils\\ArrayList' => 'nette/utils/src/Utils/ArrayList.php',
  'Nette\\Utils\\Arrays' => 'nette/utils/src/Utils/Arrays.php',
  'Nette\\Utils\\AssertionException' => 'nette/utils/src/Utils/exceptions.php',
  'Nette\\Utils\\Callback' => 'nette/utils/src/Utils/Callback.php',
  'Nette\\Utils\\DateTime' => 'nette/utils/src/Utils/DateTime.php',
  'Nette\\Utils\\FileInfo' => 'nette/utils/src/Utils/FileInfo.php',
  'Nette\\Utils\\FileSystem' => 'nette/utils/src/Utils/FileSystem.php',
  'Nette\\Utils\\Finder' => 'nette/utils/src/Utils/Finder.php',
  'Nette\\Utils\\Floats' => 'nette/utils/src/Utils/Floats.php',
  'Nette\\Utils\\Helpers' => 'nette/utils/src/Utils/Helpers.php',
  'Nette\\Utils\\Html' => 'nette/utils/src/Utils/Html.php',
  'Nette\\Utils\\Image' => 'nette/utils/src/Utils/Image.php',
  'Nette\\Utils\\ImageColor' => 'nette/utils/src/Utils/ImageColor.php',
  'Nette\\Utils\\ImageException' => 'nette/utils/src/Utils/exceptions.php',
  'Nette\\Utils\\Iterables' => 'nette/utils/src/Utils/Iterables.php',
  'Nette\\Utils\\Json' => 'nette/utils/src/Utils/Json.php',
  'Nette\\Utils\\JsonException' => 'nette/utils/src/Utils/exceptions.php',
  'Nette\\Utils\\ObjectHelpers' => 'nette/utils/src/Utils/ObjectHelpers.php',
  'Nette\\Utils\\Paginator' => 'nette/utils/src/Utils/Paginator.php',
  'Nette\\Utils\\Random' => 'nette/utils/src/Utils/Random.php',
  'Nette\\Utils\\Reflection' => 'nette/utils/src/Utils/Reflection.php',
  'Nette\\Utils\\ReflectionMethod' => 'nette/utils/src/Utils/ReflectionMethod.php',
  'Nette\\Utils\\RegexpException' => 'nette/utils/src/Utils/exceptions.php',
  'Nette\\Utils\\Strings' => 'nette/utils/src/Utils/Strings.php',
  'Nette\\Utils\\Type' => 'nette/utils/src/Utils/Type.php',
  'Nette\\Utils\\UnknownImageFileException' => 'nette/utils/src/Utils/exceptions.php',
  'Nette\\Utils\\Validators' => 'nette/utils/src/Utils/Validators.php',
);

spl_autoload_register(static function (string $class) use ($__wikiVendor, $__wikiClassmap): void {
    if (isset($__wikiClassmap[$class])) {
        require $__wikiVendor . '/' . $__wikiClassmap[$class];
        return;
    }
    static $psr4 = [
        'League\\CommonMark\\' => 'league/commonmark/src/',
        'League\\Config\\' => 'league/config/src/',
        'Dflydev\\DotAccessData\\' => 'dflydev/dot-access-data/src/',
        'Psr\\EventDispatcher\\' => 'psr/event-dispatcher/src/',
    ];
    foreach ($psr4 as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $__wikiVendor . '/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require_once $__wikiVendor . '/symfony/deprecation-contracts/function.php';
require_once $__wikiVendor . '/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
