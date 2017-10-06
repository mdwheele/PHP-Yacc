<?php

namespace PhpYacc\Lalr;

use PhpYacc\Core\ArrayObject;
use PhpYacc\Grammar\Context;
use PhpYacc\Yacc\ParseResult;
use PhpYacc\Grammar\Symbol;
use PhpYacc\Yacc\Production;

require_once __DIR__ . '/functions.php';

class Generator {

    /** @var ParseResult */
    protected $parseResult;
    /** @var Context */
    protected $context;
    protected $nullable;
    protected $blank;
    protected $statesThrough = [];
    protected $visited = [];
    protected $tail;
    protected $first;
    protected $nlooks;

    public function compute(ParseResult $parseResult)
    {
        $this->parseResult = $parseResult;
        $this->context = $parseResult->ctx;
        $nSymbols = $this->context->nSymbols();
        $this->nullable = array_fill(0, $nSymbols, false);

        $this->blank = str_repeat("\0", ceil(($nSymbols + NBITS - 1) / NBITS));
        $this->first = array_fill(0, $nSymbols, $this->blank);
        $this->follow = array_fill(0, $nSymbols, $this->blank);
        $this->nlooks = 0;
        foreach ($this->context->symbols() as $s) {
            $this->statesThrough[$s->code] = new StateList(null, null);
        }

        $this->computeEmpty();
        $this->firstNullablePrecomp();
        $this->computeKernels();
    }

    protected function computeKernels()
    {
        $tmpList = new Lr1(
            $this->parseResult->startPrime, 
            $this->blank, 
            $this->parseResult->gram(0)->body->slice(2)
        );
        $states = new State();
        $states->through = $this->context->nilSymbol();
        $states->items = $this->makeState($tmpList);


        $this->linkState($states, $states->through);
        $this->tail = $states;

        for ($p = $states; $p !== null; $p = $p->next) {
            $tmpList = $tmpTail = null;
            /** @var Lr1 $x */
            for ($x = $p->items; $x !== null; $x = $x->next) {
                if (!$x->isTailItem()) {
                    $wp = new Lr1($this->parseResult->startPrime, $this->blank, $x->item->slice(1));
                }
            }
        }
    }

    protected function linkState(State $state, Symbol $g)
    {
        $this->statesThrough[$g->code] = new StateList(
            $state, 
            $this->statesThrough[$g->code]
        );
    }

    protected function computeEmpty()
    {
        do {
            $changed = false;
            foreach ($this->parseResult->grams() as $gram) {
                $left = $gram->body[1];
                $right = $gram->body[2];
                if (($right === null || ($right->associativity & Production::EMPTY)) && !($left->associativity & Production::EMPTY)) {
                    $left->setAssociativityFlag(Production::EMPTY);
                    $changed = true;
                }
            }
        } while ($changed);

        if (DEBUG) {
            echo "EMPTY nonterminals: \n";
            foreach ($this->context->nonTerminals() as $symbol) {
                if ($symbol->associativity & Production::EMPTY) {
                    echo "  " . $symbol->name . "\n";
                }
            }
        }
    }

    protected function firstNullablePrecomp()
    {
        do {
            $changed = false;
            foreach ($this->parseResult->grams() as $gram) {
                $h = $gram->body[1];
                for ($s = 2; $s < count($gram->body) + 1; $s++) {
                    $g = $gram->body[$s];
                    if ($g->isTerminal()) {
                        if (!testBit($this->first[$h->code], $g->code)) {
                            $changed = true;
                            setBit($this->first[$h->code], $g->code);
                        }
                        continue 2;
                    }

                    $changed |= orbits(
                        $this->context, 
                        $this->first[$h->code],
                        $this->first[$g->code]
                    );
                    if (!$this->nullable[$g->code]) {
                        continue 2;
                    }
                }

                if (!$this->nullable[$h->code]) {
                    $this->nullable[$h->code] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        if (DEBUG) {
            echo "First:\n";
            foreach ($this->context->nonTerminals() as $symbol) {
                echo "  {$symbol->name}\t[ ";
                dumpSet($this->context, $this->first[$symbol->code]);
                if ($this->nullable[$symbol->code]) {
                    echo "@ ";
                }
                echo "]\n";
            }
        }
    }

    protected function makeState(Lr1 $items): Lr1
    {
        $tail = null;
        for ($p = $items; $p !== null; $p = $p->next) {
            $p->look = null;
            if ($p->left !== $this->parseResult->startPrime) {
                for ($q = $items; $q !== $p; $q = $q->next) {
                    if ($q->left === $p->left) {
                        $p->look = $q->look;
                        break;
                    }
                }
            }
            if ($p->look === null) {
                $p->look = $this->blank;
            }
            $tail = $p;
        }
        $this->clearVisited();
        for ($p = $items; $p !== null; $p = $p->next) {
            /** @var Symbol $g */
            $g = $p->item[0];
            if ($g !== null && !$g->isTerminal()) {
                $tail = $this->findEmpty($tail, $g);
            }
        }
        return $items;
    }

    protected function clearVisited()
    {
        $nSymbols = $this->context->nSymbols();
        $this->visited = array_fill(0, $nSymbols, false);

    }

    protected function findEmpty(Lr1 $tail, Symbol $x): Lr1
    {
        if (!$this->visited[$x->code] && ($x->associativity & Production::EMPTY)) {
            $this->visited[$x->code] = true;

            /** @var Production $gram */
            for ($gram = $x->value; $gram !== null; $gram = $gram->link) {
                if (count($gram->body) == 1) {
                    $p = new Lr1($this->parseResult->startPrime, $this->blank, $gram->body->slice(2));
                    $tail->next = $p;
                    $tail = $p;
                    $this->nlooks++;
                } else if (!$gram->body[2]->isTerminal()) {
                    $tail = $this->findEmpty($tail, $gram->body[2]);
                }
            }
        }
        return $tail;
    }

}