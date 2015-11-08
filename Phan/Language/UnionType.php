<?php declare(strict_types=1);
namespace Phan\Language;

use \Phan\Deprecated;
use \Phan\Language\AST\Element;
use \Phan\Language\AST\KindVisitorImplementation;
use \Phan\Language\Type;
use \Phan\Language\Type\{
    ArrayType,
    FloatType,
    IntType,
    MixedType,
    NoneType,
    StringType
};
use \Phan\Language\Type\NodeTypeKindVisitor;
use \ast\Node;

/**
 * Static data defining type names for builtin classes
 */
$BUILTIN_CLASS_TYPES =
    require(__DIR__.'/Type/BuiltinClassTypes.php');

/**
 * Static data defining types for builtin functions
 */
$BUILTIN_FUNCTION_ARGUMENT_TYPES =
    require(__DIR__.'/Type/BuiltinFunctionArgumentTypes.php');

class UnionType extends \ArrayObject  {
    use \Phan\Language\AST;

    /**
     * @param Type[] $type_list
     * An optional list of types represented by this union
     */
    public function __construct(array $type_list = []) {
        foreach ($type_list as $type) {
            $this->addType($type);
        }
    }

    /**
     * @param string $type_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     *
     * @return UnionType
     */
    public static function fromString(string $type_string) : UnionType {
        return new UnionType(
            array_map(function(string $type_name) {
                return Type::fromString($type_name);
            }, explode('|', $type_string))
        );
    }

    /**
     * ast_node_type() is for places where an actual type
     * name appears. This returns that type name. Use node_type()
     * instead to figure out the type of a node
     *
     * @param Context $context
     * @param null|string|Node $node
     *
     * @see \Phan\Deprecated\AST::ast_node_type
     */
    public static function fromSimpleNode(
        Context $context,
        $node
    ) : UnionType {
        return self::astUnionTypeFromSimple($context, $node);
    }

    /**
     * @param Context $context
     * @param Node|string|null $node
     *
     * @return UnionType
     *
     * @see \Phan\Deprecated\Pass2::node_type
     * Formerly 'function node_type'
     */
    public static function fromNode(
        Context $context,
        $node
    ) : UnionType {
        if(!($node instanceof Node)) {
            if($node === null) {
                return new UnionType();
            }

            return Type::fromObject($node);
        }

        return (new Element($node))->acceptKindVisitor(
            new NodeTypeKindVisitor($context)
        );
	}

    public static function builtinClassPropertyType(
        string $class_name,
        string $property_name
    ) : UnionType {
        $class_property_type_map =
            $BUILTIN_CLASS_TYPES[strtolower($class_name)]['properties'];

        $property_type_name =
            $class_property_type_map[$property_name];

        return new UnionType([$property_type_name]);
    }

    /**
     * @return UnionType[]
     * A list of types for parameters associated with the
     * given builtin function with the given name
     */
    public static function builtinFunctionPropertyNameTypeMap(
        FQSEN $function_fqsen
    ) : array {
        $type_name_struct =
            $BUILTIN_FUNCTION_ARGUMENT_TYPES[$function_fqsen->__toString()];

        if (!$type_name_struct) {
            return [];
        }

        $type_return = array_shift($type_name_struct);
        $name_type_name_map = $type_name_struct;

        $property_name_type_map = [];

        foreach ($name_type_name_map as $name => $type_name) {
            $property_name_type_map[$name] =
                new UnionType([$type_name]);
        }

        return $property_name_type_map;
    }

    /**
     * @return bool
     * True if a builtin with the given FQSEN exists, else
     * flase.
     */
    public static function builtinExists(FQSEN $fqsen) : bool {
        return !empty(
            $BUILTIN_FUNCTION_ARGUMENT_TYPES[(string)$fqsen]
        );
    }

    /**
     * @return Type
     * Get the first type in this set
     */
    public function head() : Type {
        return array_values($this)[0];
    }

    /**
     * Add a type name to the list of types
     *
     * @return null
     */
    public function addType(Type $type) {
        // Only allow unique elements
        if ($type) {
            $this[(string)$type] = $type;
        }
    }

    /**
     * Add the given types to this type
     *
     * @return null
     */
    public function addUnionType(UnionType $union_type) {
        foreach ($union_type->getTypeList() as $i => $type) {
            $this->addType($type);
        }
    }

    /**
     * @return bool
     * True if this union type contains the given named
     * type.
     */
    public function hasType(Type $type) : bool {
        return isset($this[$type]);
    }

    /**
     * @return bool
     * True if this type has a type referencing the
     * class context in which it exists such as 'static'
     * or 'self'.
     */
    public function hasSelfType() : bool {
        return array_reduce($this,
            function (bool $carry, Type $type) : bool {
                return ($carry || $type->isSelfType());
            }, false);
    }

    /**
     * @return bool
     * True if and only if this UnionType contains
     * the given type and no others.
     */
    public function isType(Type $type) : bool {
        if (empty($this) || count($this) > 1) {
            return false;
        }

        return ($this->head() === $type);
    }

    /**
     * @return bool
     * True iff this union contains the exact set of types
     * represented in the given union type.
     */
    public function isEqualTo(UnionType $union_type) : bool {
        return ((string)$this === (string)$union_type);
    }

    /**
     * @param Type[] $type_list
     * A list of types
     *
     * @return bool
     * True if this union type contains any of the given
     * named types
     */
    public function hasAnyType(array $type_list) : bool {
        return array_reduce($type_list,
            function(bool $carry, Type $type)  {
                return ($carry || $this->hasType($type));
            }, false);
    }

    /**
     * @return int
     * The number of types in this union type
     */
    public function typeCount() : int {
        return count($this);
    }

    /**
     * @param UnionType $target
     * A type to check to see if this can cast to it
     *
     * @return bool
     * True if this type is allowed to cast to the given type
     * i.e. int->float is allowed  while float->int is not.
     *
     * @see \Phan\Deprecated\Pass2::type_check
     * Formerly 'function type_check'
     */
    public function canCastToUnionType(
        UnionType $target
    ) : bool {
        // Fast-track most common cases first
        if ($this->isEqualTo($target)) {
            return true;
        }

        // If either type is unknown, we can't call it
        // a success
        if(empty($this) || empty($target)) {
            return true;
        }

        // null <-> null
        if ($this->isType(NullType::instance())
            || $target->isType(NullType::instance())
        ) {
            return true;
        }

        // mixed <-> mixed
        if ($target->hasType(MixedType::instance())
            || $this->hasType(MixedType::instance())
        ) {
            return true;
        }

        // int -> float
        if ($this->isType(IntType::instance())
            && $target->isType(FloatType::instance())
        ) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        foreach($this as $source_type) {
            if(empty($source_type)) {
                continue;
            }
            foreach($target as $target_type) {
                if(empty($target_type)) {
                    continue;
                }

                if ($source_type->canCastToType($target_type)) {
                    return true;
                }
            }
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * @return bool
     * True if all types in this union are scalars
     *
     * @see \Phan\Deprecated\Util::type_scalar
     * Formerly `function type_scalar`
     */
    public function isScalar() : bool {
        if (empty($this) || count($this) > 1) {
            return false;
        }

        return $this[0]->isScalar();
    }

    /**
     * Takes "a|b[]|c|d[]|e" and returns "b|d"
     *
     * @return UnionType
     * The subset of types in this
     */
    public function genericTypes() : UnionType {
        $str = (string)$this;

        // If array is in there, then it can be any type
        // Same for |mixed|
        if ($this->hasType(ArrayType::instance())
            || $this->hasTypeName(MixedType::instance())
        ) {
            return MixedType::instance()->toUnionType();
        }

        if ($this->hasType(ArrayType::instance())) {
            return NoneType::instance()->toUnionType();
        }

        return new UnionType(array_filter(array_map($this,
            function(Type $type) {
                if(($pos = strpos((string)$type, '[]')) === false) {
                    return null;
                }

                return substr((string)$type, 0, $pos);
            }))
        );
    }

    /**
     * @return UnionType
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'int[]|float[]'.
     */
    public function asGenericTypes() {
        return array_map(function (Type $type) : Type {
            return $type->asGenericType();
        }, $this);
    }

    /**
     * Takes "a|b[]|c|d[]|e" and returns "a|c|e"
     *
     * @return UnionType
     * A UnionType with generic types filtered out
     *
     * @see \Phan\Deprecated\Pass2::nongenerics
     * Formerly `function nongenerics`
     */
    public function nonGenericTypes() : UnionType {
        return array_filter($this, function(Type $type) {
            return !$type->isGeneric();
        });

        /*
        $str = (string)$this;

        $type_names = [];
        foreach($this->type_name_list as $type_name) {
            if(($pos = strpos($type_name, '[]')) !== false) {
                continue;
            }

            // TODO: this was `if ($str == 'array') {`. Thats broken, right?
            if($type_name == 'array') {
                continue;
            }

            $type_names[] = $type_name;
        }

        return new UnionType($type_names);
         */
    }

    /**
     * As per the Serializable interface
     *
     * @return string
     * A serialized representation of this type
     *
     * @see \Serializable
     */
    public function serialize() : string {
        return (string)$this;
    }

    /**
     * As per the Serializable interface
     *
     * @param string $serialized
     * A serialized UnionType
     *
     * @return UnionType
     * A UnionType representing the given serialized form
     *
     * @see \Serializable
     */
    public function unserialize($serialized) {
        return self::fromString($serialized);
    }

    /**
     * @return string
     * A human-readable string representation of this union
     * type
     */
    public function __toString() : string {
        // Sort the types so that we get a stable
        // representation
        self::natsort();

        // Delimit by '|'
        return implode('|', array_map(function(Type $type) : string {
            return (string)$type;
        }, $this->getArrayCopy()));
    }

}