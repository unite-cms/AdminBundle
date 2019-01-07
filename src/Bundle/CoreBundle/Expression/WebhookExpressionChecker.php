<?php

namespace UniteCMS\CoreBundle\Expression;

use Symfony\Component\ExpressionLanguage\SyntaxError;
use UniteCMS\CoreBundle\Entity\FieldableContent;

/**
 * @deprecated 0.8 This checker will be replaced by UniteExpressionChecker which have other variables: content, member
 * "locale" and "data" variable of this checker will become "content.locale" und "content.X" in version 0.8
 */
class WebhookExpressionChecker
{

   /**
     * Returns the expression evaluation result, makes the possible doctrine events available
     *
     * @param string $expression
     * @param string $eventName
     * @param FieldableContent|null $fieldableContent
     * @return bool
     */
    public function evaluate(string $expression, string $eventName, FieldableContent $fieldableContent) : bool {

        $expressionLanguage = new UniteExpressionLanguage();

        $variables = [
          'locale' => $fieldableContent->getLocale(),
          'data' => json_decode(json_encode($fieldableContent->getData())),
          'event' => $eventName
        ];

        try {
            return (bool) $expressionLanguage->evaluate($expression, $variables);
        }

        // Silently cache all exceptions. The expression can be defined by the user and we don't want to show him_her an error page.
        catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * Returns true, of the expression is valid (syntax and allowed variables).
     *
     * @param string $expression
     * @return bool
     */
    public function validate(string $expression) : bool {
        $expressionLanguage = new UniteExpressionLanguage();
        $variables = ['event', 'locale', 'data'];

        try {
            $expressionLanguage->parse($expression, $variables);
        }

        catch (SyntaxError $error) {
            return false;
        }

        return true;
    }
}