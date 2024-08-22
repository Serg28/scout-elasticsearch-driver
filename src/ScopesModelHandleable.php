<?php

namespace Novius\ScoutElastic;

use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use ReflectionClass;

/*
 *  Usage scopes model

When you have written a series of scope methods for your model and you want to use it in elastic, do it easily by adding a trait class.

Basic usage example:

<?php

namespace App;

use ScoutElastic\ScopesModelHandleable;
use Illuminate\Database\Eloquent\Model;

class MyModel extends Model
{
    use ScopesModelHandleable;

    // You can set several scopes for one model. In this case, the first not empty result will be returned.
    protected $scopesElastic = [
        'scopePublished',
         ...
    ];

    public function scopePublished($query)
    {
        return $query->where('has_published', true);
    }

}
*/
trait ScopesModelHandleable
{
    public function initializeScopesModelHandleable()
    {
        $this->handleScopeMethods();
    }

    private function handleScopeMethods()
    {
        $context = $this;
        $reflectionClass = new ReflectionClass(self::class);

        if ($reflectionClass->hasProperty('scopesElastic')) {
            foreach ($this->scopesElastic as $method) {
                if ($reflectionClass->hasMethod($method)) {
                    $refMethod = $reflectionClass->getMethod($method);
                    $method = Str::of($method)->replaceFirst('scope', '')->camel()->__toString();

                    Builder::macro($method, function (...$args) use ($context, $refMethod) {
                        if (Str::is('scope*', $refMethod->getName())) {
                            $args[] = $this;
                            $args = array_reverse($args);

                            call_user_func_array([$context, $refMethod->getName()], $args);
                        }
                    });
                }
            }
        }
    }
}