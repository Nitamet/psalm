<?php
namespace Psalm\Checker;

use PhpParser;

class ScopeChecker
{
    const ACTION_END = 'END';
    const ACTION_BREAK = 'BREAK';
    const ACTION_CONTINUE = 'CONTINUE';
    const ACTION_NONE = 'NONE';

    /**
     * @param   array<PhpParser\Node>   $stmts
     *
     * @return  bool
     */
    public static function doesEverBreak(array $stmts)
    {
        if (empty($stmts)) {
            return false;
        }

        for ($i = count($stmts) - 1; $i >= 0; --$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                return true;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                if (self::doesEverBreak($stmt->stmts)) {
                    return true;
                }

                if ($stmt->else && self::doesEverBreak($stmt->else->stmts)) {
                    return true;
                }

                foreach ($stmt->elseifs as $elseif) {
                    if (self::doesEverBreak($elseif->stmts)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param   array<PhpParser\Node> $stmts
     * @param   bool $continue_is_break when checking inside a switch statement, continue is an alias of break
     *
     * @return  string[] one or more of 'LEAVE', 'CONTINUE', 'BREAK' (or empty if no single action is found)
     */
    public static function getFinalControlActions(array $stmts, $continue_is_break = false)
    {
        if (empty($stmts)) {
            return [self::ACTION_NONE];
        }

        $control_actions = [];

        for ($i = 0, $c = count($stmts); $i < $c; ++$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Return_ ||
                $stmt instanceof PhpParser\Node\Stmt\Throw_ ||
                $stmt instanceof PhpParser\Node\Expr\Exit_
            ) {
                return [self::ACTION_END];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                if ($continue_is_break
                    && (!$stmt->num || !$stmt->num instanceof PhpParser\Node\Scalar\LNumber || $stmt->num->value < 2)
                ) {
                    return [self::ACTION_BREAK];
                }

                return [self::ACTION_CONTINUE];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                return [self::ACTION_BREAK];
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $if_statement_actions = self::getFinalControlActions($stmt->stmts, $continue_is_break);
                $else_statement_actions = $stmt->else
                    ? self::getFinalControlActions($stmt->else->stmts, $continue_is_break)
                    : [];

                $all_same = count($if_statement_actions) === 1
                    && $if_statement_actions == $else_statement_actions
                    && $if_statement_actions !== [self::ACTION_NONE];

                $all_elseif_actions = [];

                if ($stmt->elseifs) {
                    foreach ($stmt->elseifs as $elseif) {
                        $elseif_control_actions = self::getFinalControlActions($elseif->stmts, $continue_is_break);

                        $all_same = $all_same && $elseif_control_actions == $if_statement_actions;

                        if (!$all_same) {
                            $all_elseif_actions = array_merge($elseif_control_actions, $all_elseif_actions);
                        }
                    }
                }

                if ($all_same) {
                    return $if_statement_actions;
                }

                $control_actions = array_merge(
                    $control_actions,
                    $if_statement_actions,
                    $else_statement_actions,
                    $all_elseif_actions
                );
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $has_returned = false;
                $has_default_terminator = false;

                // iterate backwards in a case statement
                for ($d = count($stmt->cases) - 1; $d >= 0; --$d) {
                    $case = $stmt->cases[$d];

                    $case_actions = self::getFinalControlActions($case->stmts, true);

                    if (array_intersect([self::ACTION_BREAK, self::ACTION_CONTINUE], $case_actions)) {
                        continue 2;
                    }

                    $case_does_return = $case_actions == [self::ACTION_END];

                    if ($case_does_return) {
                        $has_returned = true;
                    }

                    if (!$case_does_return && !$has_returned) {
                        continue 2;
                    }

                    if (!$case->cond && $case_does_return) {
                        $has_default_terminator = true;
                    }
                }

                if ($has_default_terminator) {
                    return [self::ACTION_END];
                }
            }

            if ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $control_actions = array_merge(self::getFinalControlActions($stmt->stmts), $control_actions);
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $do_actions = self::getFinalControlActions($stmt->stmts);

                if (count($do_actions) && !in_array(self::ACTION_NONE, $do_actions, true)) {
                    return $do_actions;
                }

                $control_actions = array_merge($control_actions, $do_actions);
            }

            if ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $try_statement_actions = self::getFinalControlActions($stmt->stmts, $continue_is_break);

                if ($stmt->catches) {
                    $all_same = count($try_statement_actions) === 1;

                    foreach ($stmt->catches as $catch) {
                        $catch_actions = self::getFinalControlActions($catch->stmts, $continue_is_break);

                        $all_same = $all_same && $try_statement_actions == $catch_actions;

                        if (!$all_same) {
                            $control_actions = array_merge($control_actions, $catch_actions);
                        }
                    }

                    if ($all_same && $try_statement_actions !== [self::ACTION_NONE]) {
                        return $try_statement_actions;
                    }
                }

                $control_actions = array_merge($control_actions, $try_statement_actions);
            }
        }

        $control_actions[] = self::ACTION_NONE;

        return array_unique($control_actions);
    }

    /**
     * @param   array<PhpParser\Node> $stmts
     *
     * @return  bool
     */
    public static function onlyThrows(array $stmts)
    {
        if (empty($stmts)) {
            return false;
        }

        for ($i = count($stmts) - 1; $i >= 0; --$i) {
            $stmt = $stmts[$i];

            if ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                return true;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                continue;
            }

            return false;
        }

        return false;
    }
}
