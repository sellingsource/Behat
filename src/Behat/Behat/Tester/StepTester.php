<?php

namespace Behat\Behat\Tester;

use Symfony\Component\DependencyInjection\ContainerInterface,
    Symfony\Component\EventDispatcher\Event;

use Behat\Gherkin\Node\NodeVisitorInterface,
    Behat\Gherkin\Node\AbstractNode,
    Behat\Gherkin\Node\StepNode;

use Behat\Behat\Context\ContextInterface,
    Behat\Behat\Context\Step\SubstepInterface,
    Behat\Behat\Definition\DefinitionInterface,
    Behat\Behat\Exception\AmbiguousException,
    Behat\Behat\Exception\UndefinedException,
    Behat\Behat\Exception\PendingException,
    Behat\Behat\Event\StepEvent;

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Step Tester.
 *
 * @author      Konstantin Kudryashov <ever.zet@gmail.com>
 */
class StepTester implements NodeVisitorInterface
{
    /**
     * Event dispatcher.
     *
     * @var     Symfony\Component\EventDispatcher\EventDispatcher
     */
    private $dispatcher;
    /**
     * Context.
     *
     * @var     Behat\Behat\Context\ContextInterface
     */
    private $context;
    /**
     * Definition dispatcher.
     *
     * @var     Behat\Behat\Definition\DefinitionDispatcher
     */
    private $definitions;
    /**
     * Step replace tokens.
     *
     * @var     array
     */
    private $tokens = array();
    /**
     * Is step marked as skipped.
     *
     * @var     boolean
     */
    private $skip = false;

    /**
     * Initializes tester.
     *
     * @param   Symfony\Component\DependencyInjection\ContainerInterface    $container  service container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->dispatcher   = $container->get('behat.event_dispatcher');
        $this->definitions  = $container->get('behat.definition_dispatcher');
    }

    /**
     * Sets run context.
     *
     * @param   Behat\Behat\Context\ContextInterface    $context
     */
    public function setContext(ContextInterface $context)
    {
        $this->context = $context;
    }

    /**
     * Sets step replacements for tokens.
     *
     * @param   array   $tokens     step tokens
     */
    public function setTokens(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * Marks test as skipped.
     *
     * @param   boolean $skip   skip test?
     */
    public function skip($skip = true)
    {
        $this->skip = $skip;
    }

    /**
     * Visits & tests StepNode.
     *
     * @param   Behat\Gherkin\Node\AbstractNode $step
     *
     * @return  integer
     */
    public function visit(AbstractNode $step)
    {
        $step->setTokens($this->tokens);

        $this->dispatcher->dispatch('beforeStep', new StepEvent($step, $this->context));
        $afterEvent = $this->executeStep($step);
        $this->dispatcher->dispatch('afterStep', $afterEvent);

        return $afterEvent->getResult();
    }

    /**
     * Searches and runs provided step with DefinitionDispatcher.
     *
     * @param   Behat\Gherkin\Node\StepNode $step   step node
     *
     * @return  Behat\Behat\Event\StepEvent
     */
    protected function executeStep(StepNode $step)
    {
        $context    = $this->context;
        $result     = null;
        $definition = null;
        $exception  = null;
        $snippet    = null;

        try {
            $definition = $this->definitions->findDefinition($this->context, $step);

            if ($this->skip) {
                return new StepEvent($step, $context, StepEvent::SKIPPED, $definition);
            }

            try {
                $this->runStepDefinition($definition);
                $result = StepEvent::PASSED;
            } catch (PendingException $e) {
                $result    = StepEvent::PENDING;
                $exception = $e;
            } catch (\Exception $e) {
                $result    = StepEvent::FAILED;
                $exception = $e;
            }
        } catch (UndefinedException $e) {
            $result    = StepEvent::UNDEFINED;
            $snippet   = $this->definitions->proposeDefinition($this->context, $step);
            $exception = $e;
        } catch (\Exception $e) {
            $result    = StepEvent::FAILED;
            $exception = $e;
        }

        return new StepEvent($step, $context, $result, $definition, $exception, $snippet);
    }

    /**
     * Runs provided step definition.
     *
     * @param   Behat\Behat\Definition\DefinitionInterface  $definition step definition
     */
    protected function runStepDefinition(DefinitionInterface $definition)
    {
        $returnValue = $definition->run($this->context, $this->tokens);
        if (null === $returnValue) {
            return;
        }

        $returnValues = is_array($returnValue) ? $returnValue : array($returnValue);
        foreach ($returnValues as $value) {
            if ($value instanceof SubstepInterface) {
                $substep = $value->getStepNode();
                $this->dispatcher->dispatch('beforeSubStep', new StepEvent($substep, $this->context));
                $substepEvent = $this->executeStep($substep);
                if (StepEvent::PASSED !== $substepEvent->getResult()) {
                    throw $substepEvent->getException();
                }
                $this->dispatcher->dispatch('afterSubStep', $substepEvent);
            }
        }
    }
}
