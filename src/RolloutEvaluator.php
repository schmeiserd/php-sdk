<?php

namespace ConfigCat;

use ConfigCat\Attributes\PercentageAttributes;
use ConfigCat\Attributes\RolloutAttributes;
use ConfigCat\Attributes\SettingAttributes;
use Exception;
use Psr\Log\LoggerInterface;
use z4kn4fein\SemVer\SemverException;
use z4kn4fein\SemVer\Version;

/**
 * Class RolloutEvaluator.
 *
 * @internal
 */
final class RolloutEvaluator
{
    private array $comparatorTexts = [
        'IS ONE OF',
        'IS NOT ONE OF',
        'CONTAINS',
        'DOES NOT CONTAIN',
        'IS ONE OF (SemVer)',
        'IS NOT ONE OF (SemVer)',
        '< (SemVer)',
        '<= (SemVer)',
        '> (SemVer)',
        '>= (SemVer)',
        '= (Number)',
        '<> (Number)',
        '< (Number)',
        '<= (Number)',
        '> (Number)',
        '>= (Number)',
        'IS ONE OF (Sensitive)',
        'IS NOT ONE OF (Sensitive)',
    ];

    /**
     * RolloutEvaluator constructor.
     *
     * @param LoggerInterface $logger the logger instance
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Evaluates a requested value from the configuration by the specified roll-out rules.
     *
     * @param string                 $key          the key of the desired value
     * @param array                  $json         the decoded JSON configuration
     * @param EvaluationLogCollector $logCollector the evaluation log collector
     * @param User|null              $user         Optional. The user to identify the caller.
     *
     * @return EvaluationResult the evaluation result
     */
    public function evaluate(
        string $key,
        array $json,
        EvaluationLogCollector $logCollector,
        User $user = null
    ): EvaluationResult {
        if (null === $user) {
            if (isset($json[SettingAttributes::ROLLOUT_RULES]) &&
                !empty($json[SettingAttributes::ROLLOUT_RULES]) ||
                isset($json[SettingAttributes::ROLLOUT_PERCENTAGE_ITEMS]) &&
                !empty($json[SettingAttributes::ROLLOUT_PERCENTAGE_ITEMS])) {
                $this->logger->warning('UserObject missing! You should pass a '.
                    'UserObject to getValue() in order to make targeting work properly. '.
                    'Read more: https://configcat.com/docs/advanced/user-object.');
            }

            $result = $json[SettingAttributes::VALUE];
            $variationId = $json[SettingAttributes::VARIATION_ID] ?? '';
            $logCollector->add('Returning '.Utils::getStringRepresentation($result).'.');

            return new EvaluationResult($result, $variationId, null, null);
        }

        $logCollector->add('User object: '.$user);
        if (isset($json[SettingAttributes::ROLLOUT_RULES]) && !empty($json[SettingAttributes::ROLLOUT_RULES])) {
            foreach ($json[SettingAttributes::ROLLOUT_RULES] as $rule) {
                $comparisonAttribute = $rule[RolloutAttributes::COMPARISON_ATTRIBUTE];
                $comparisonValue = $rule[RolloutAttributes::COMPARISON_VALUE];
                $comparator = $rule[RolloutAttributes::COMPARATOR];
                $value = $rule[RolloutAttributes::VALUE];
                $variationId = $rule[RolloutAttributes::VARIATION_ID] ?? '';
                $userValue = $user->getAttribute($comparisonAttribute);

                if (empty($comparisonValue) || (!is_numeric($userValue) && empty($userValue))) {
                    $logCollector->add($this->logNoMatch(
                        $comparisonAttribute,
                        $userValue,
                        $comparator,
                        $comparisonValue
                    ));
                    continue;
                }

                switch ($comparator) {
                    // IS ONE OF
                    case 0:
                        $split = array_filter(Utils::splitTrim($comparisonValue));
                        if (\in_array($userValue, $split, true)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // IS NOT ONE OF
                    case 1:
                        $split = array_filter(Utils::splitTrim($comparisonValue));
                        if (!\in_array($userValue, $split, true)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // CONTAINS
                    case 2:
                        if (Utils::strContains($userValue, $comparisonValue)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // DOES NOT CONTAIN
                    case 3:
                        if (!Utils::strContains($userValue, $comparisonValue)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // IS ONE OF, IS NOT ONE OF (SemVer)
                    case 4:
                    case 5:
                        $split = array_filter(Utils::splitTrim($comparisonValue));
                        try {
                            $matched = false;
                            foreach ($split as $semVer) {
                                $matched = Version::equal($userValue, $semVer) || $matched;
                            }

                            if (($matched && 4 == $comparator) || (!$matched && 5 == $comparator)) {
                                $logCollector->add($this->logMatch(
                                    $comparisonAttribute,
                                    $userValue,
                                    $comparator,
                                    $comparisonValue,
                                    $value
                                ));

                                return new EvaluationResult($value, $variationId, $rule, null);
                            }
                        } catch (SemverException $exception) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));
                            break;
                        }

                        break;
                        // LESS THAN, LESS THAN OR EQUALS TO, GREATER THAN, GREATER THAN OR EQUALS TO (SemVer)
                    case 6:
                    case 7:
                    case 8:
                    case 9:
                        try {
                            if ((6 == $comparator &&
                                    Version::lessThan($userValue, $comparisonValue)) ||
                                (7 == $comparator &&
                                    Version::lessThanOrEqual($userValue, $comparisonValue)) ||
                                (8 == $comparator &&
                                    Version::greaterThan($userValue, $comparisonValue)) ||
                                (9 == $comparator &&
                                    Version::greaterThanOrEqual($userValue, $comparisonValue))) {
                                $logCollector->add($this->logMatch(
                                    $comparisonAttribute,
                                    $userValue,
                                    $comparator,
                                    $comparisonValue,
                                    $value
                                ));

                                return new EvaluationResult($value, $variationId, $rule, null);
                            }
                        } catch (SemverException $exception) {
                            $logCollector->add($this->logFormatError(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $exception
                            ));
                            break;
                        }
                        break;
                        // LESS THAN, LESS THAN OR EQUALS TO, GREATER THAN, GREATER THAN OR EQUALS TO (Number)
                    case 10:
                    case 11:
                    case 12:
                    case 13:
                    case 14:
                    case 15:
                        $userDouble = str_replace(',', '.', $userValue);
                        $comparisonDouble = str_replace(',', '.', (string) $comparisonValue);
                        if (!is_numeric($userDouble)) {
                            $logCollector->add($this->logFormatErrorWithMessage(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $userDouble.'is not a valid number.'
                            ));
                            break;
                        }

                        if (!is_numeric($comparisonDouble)) {
                            $logCollector->add($this->logFormatErrorWithMessage(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $comparisonDouble.'is not a valid number.'
                            ));
                            break;
                        }

                        $userDoubleValue = (float) $userDouble;
                        $comparisonDoubleValue = (float) $comparisonDouble;

                        if ((10 == $comparator && $userDoubleValue === $comparisonDoubleValue) ||
                            (11 == $comparator && $userDoubleValue !== $comparisonDoubleValue) ||
                            (12 == $comparator && $userDoubleValue < $comparisonDoubleValue) ||
                            (13 == $comparator && $userDoubleValue <= $comparisonDoubleValue) ||
                            (14 == $comparator && $userDoubleValue > $comparisonDoubleValue) ||
                            (15 == $comparator && $userDoubleValue >= $comparisonDoubleValue)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // IS ONE OF (Sensitive)
                    case 16:
                        $split = array_filter(Utils::splitTrim($comparisonValue));
                        if (\in_array(sha1($userValue), $split, true)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                        // IS NOT ONE OF (Sensitive)
                    case 17:
                        $split = array_filter(Utils::splitTrim($comparisonValue));
                        if (!\in_array(sha1($userValue), $split, true)) {
                            $logCollector->add($this->logMatch(
                                $comparisonAttribute,
                                $userValue,
                                $comparator,
                                $comparisonValue,
                                $value
                            ));

                            return new EvaluationResult($value, $variationId, $rule, null);
                        }
                        break;
                }
                $logCollector->add($this->logNoMatch($comparisonAttribute, $userValue, $comparator, $comparisonValue));
            }
        }

        if (isset($json[SettingAttributes::ROLLOUT_PERCENTAGE_ITEMS]) &&
            !empty($json[SettingAttributes::ROLLOUT_PERCENTAGE_ITEMS])) {
            $hashCandidate = $key.$user->getIdentifier();
            $stringHash = substr(sha1($hashCandidate), 0, 7);
            $intHash = \intval($stringHash, 16);
            $scale = $intHash % 100;

            $bucket = 0;
            foreach ($json[SettingAttributes::ROLLOUT_PERCENTAGE_ITEMS] as $rule) {
                $bucket += $rule[PercentageAttributes::PERCENTAGE];
                if ($scale < $bucket) {
                    $result = $rule[PercentageAttributes::VALUE];
                    $variationId = $rule[PercentageAttributes::VARIATION_ID];
                    $logCollector->add(
                        'Evaluating % options. Returning '.Utils::getStringRepresentation($result).'.'
                    );

                    return new EvaluationResult($result, $variationId, null, $rule);
                }
            }
        }

        $result = $json[SettingAttributes::VALUE];
        $variationId = $json[SettingAttributes::VARIATION_ID] ?? '';
        $logCollector->add('Returning '.Utils::getStringRepresentation($result).'.');

        return new EvaluationResult($result, $variationId, null, null);
    }

    private function logMatch($comparisonAttribute, $userValue, $comparator, $comparisonValue, $value): string
    {
        return 'Evaluating rule: ['.$comparisonAttribute.':'.$userValue.'] '.
            '['.$this->comparatorTexts[$comparator].'] '.
            '['.$comparisonValue.'] => match, returning: '.Utils::getStringRepresentation($value).'.';
    }

    private function logNoMatch($comparisonAttribute, $userValue, $comparator, $comparisonValue): string
    {
        return 'Evaluating rule: ['.$comparisonAttribute.':'.$userValue.'] '.
            '['.$this->comparatorTexts[$comparator].'] '.
            '['.$comparisonValue.'] => no match.';
    }

    private function logFormatError(
        $comparisonAttribute,
        $userValue,
        $comparator,
        $comparisonValue,
        Exception $exception
    ): string {
        $message = 'Evaluating rule: ['.$comparisonAttribute.':'.$userValue.'] '.
            '['.$this->comparatorTexts[$comparator].'] '.
            '['.$comparisonValue.'] => SKIP rule. Validation error: '.$exception->getMessage().'.';
        $this->logger->warning($message, ['exception' => $exception]);

        return $message;
    }

    private function logFormatErrorWithMessage(
        $comparisonAttribute,
        $userValue,
        $comparator,
        $comparisonValue,
        $message
    ): string {
        $message = 'Evaluating rule: ['.$comparisonAttribute.':'.$userValue.'] '.
            '['.$this->comparatorTexts[$comparator].'] '.
            '['.$comparisonValue.'] => SKIP rule. Validation error: '.$message.'.';
        $this->logger->warning($message);

        return $message;
    }
}
