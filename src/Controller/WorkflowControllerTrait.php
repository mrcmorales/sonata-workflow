<?php

declare(strict_types=1);

namespace Yokai\SonataWorkflow\Controller;

use Psr\Container\ContainerInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Exception\LockException;
use Sonata\AdminBundle\Exception\ModelManagerException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Workflow\Exception\InvalidArgumentException;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 *
 * @author Yann Eugoné <eugone.yann@gmail.com>
 */
trait WorkflowControllerTrait
{
    private Registry $workflowRegistry;

    /**
     * @required Symfony DI autowiring
     */
    public function setWorkflowRegistry(Registry $workflowRegistry): void
    {
        $this->workflowRegistry = $workflowRegistry;
    }

    public function workflowApplyTransitionAction(Request $request): Response
    {
        $id = $request->get($this->admin->getIdParameter());

        $existingObject = $this->admin->getObject($id);

        if (!$existingObject) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->setSubject($existingObject);
        $this->admin->checkAccess('applyTransitions', $existingObject);

        $objectId = $this->admin->getNormalizedIdentifier($existingObject);

        try {
            $workflow = $this->getWorkflow($existingObject);
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException('Not found', $exception);
        }

        $transition = $request->get('transition', null);
        if ($transition === null) {
            throw new BadRequestHttpException('missing transition to apply');
        }

        if (!$workflow->can($existingObject, $transition)) {
            throw new BadRequestHttpException(
                sprintf(
                    'transition %s could not be applied to object %s',
                    $transition,
                    $this->admin->toString($existingObject)
                )
            );
        }

        $response = $this->preApplyTransition($existingObject, $transition);
        if ($response !== null) {
            return $response;
        }

        try {
            $workflow->apply($existingObject, $transition);
            $existingObject = $this->admin->update($existingObject);

            if ($this->isXmlHttpRequest($request)) {
                return $this->renderJson(
                    [
                        'result' => 'ok',
                        'objectId' => $objectId,
                        'objectName' => $this->escapeHtml($this->admin->toString($existingObject)),
                    ],
                    200,
                    []
                );
            }

            $this->addFlash(
                'sonata_flash_success',
                $this->trans(
                    'flash_edit_success',
                    ['%name%' => $this->escapeHtml($this->admin->toString($existingObject))],
                    'SonataAdminBundle'
                )
            );
        } catch (LogicException $e) {
            throw new BadRequestHttpException(
                sprintf(
                    'transition %s could not be applied to object %s',
                    $transition,
                    $this->admin->toString($existingObject)
                ),
                $e
            );
        } catch (ModelManagerException $e) {
            $this->handleModelManagerException($e);
        } catch (LockException $e) {
            $this->addFlash(
                'sonata_flash_error',
                $this->trans(
                    'flash_lock_error',
                    [
                        '%name%' => $this->escapeHtml($this->admin->toString($existingObject)),
                        '%link_start%' => '<a href="' . $this->admin->generateObjectUrl('edit', $existingObject) . '">',
                        '%link_end%' => '</a>',
                    ],
                    'SonataAdminBundle'
                )
            );
        }

        return $this->redirectTo($request, $existingObject);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function getWorkflow(object $object): WorkflowInterface
    {
        $registry = $this->workflowRegistry ?? null;
        if ($registry === null) {
            try {
                if (method_exists($this, 'get')) {
                    $registry = $this->get('workflow.registry');
                } elseif (method_exists($this, 'getContainer')) {
                    $registry = $this->getContainer()->get('workflow.registry');
                } else {
                    $registry = $this->container->get('workflow.registry');
                }
            } catch (ServiceNotFoundException $exception) {
                throw new \LogicException(
                    'Could not find the "workflow.registry" service. ' .
                    'You should either provide it via setter injection in your controller service definition ' .
                    'or make it public in your project.',
                    0,
                    $exception
                );
            }
        }

        return $registry->get($object);
    }

    protected function preApplyTransition(object $object, string $transition): ?Response
    {
        return null;
    }
}
