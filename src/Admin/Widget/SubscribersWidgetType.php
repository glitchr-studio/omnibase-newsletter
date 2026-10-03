<?php

namespace Base\Newsletter\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Newsletter\Repository\SubscriberRepository;

/**
 * The dashboard's tile: how many read the letters, how many have not
 * confirmed yet, the last few to sign up, each opening the list.
 * `yield MenuItem::block('newsletter_subscribers', ...)` in the dashboard's
 * configureWidgetItems() places it.
 */
final class SubscribersWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly SubscriberRepository $subscribers)
    {
    }

    public static function getName(): string
    {
        return 'newsletter_subscribers';
    }

    public function getTemplate(): string
    {
        return '@Newsletter/admin/widget/subscribers.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        return [
            'confirmed' => $this->subscribers->countConfirmed(),
            'pending' => $this->subscribers->countPending(),
            'subscribers' => $this->subscribers->findLatest(5),
        ];
    }
}
