<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Events\ResourceLifecycleEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidFcmOptions;
use NotificationChannels\Fcm\Resources\AndroidNotification;
use NotificationChannels\Fcm\Resources\ApnsConfig;
use NotificationChannels\Fcm\Resources\ApnsFcmOptions;

class OrderPing extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var \Fleetbase\FleetOps\Models\Order
     */
    public Order $order;

    /**
     * Distance of order pickup from driver.
     *
     * @var int
     */
    public $distance;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order, $distance = null)
    {
        $this->order = $order->setRelations([]);
        $this->distance = $distance;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the type of the notification being broadcast.
     *
     * @return string
     */
    public function broadcastType()
    {
        return 'order.ping';
    }

     /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('company.' . session('company', data_get($this->order, 'company.uuid'))),
            new Channel('company.' . data_get($this->order, 'company.public_id')),
            new Channel('api.' . session('api_credential')),
            new Channel('order.' . $this->order->uuid),
            new Channel('order.' . $this->order->public_id)
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        $model = $this->order;
        $resource = new OrderResource($model);
        $resourceData = [];

        if ($resource) {
            if (method_exists($resource, 'toWebhookPayload')) {
                $resourceData = $resource->toWebhookPayload();
            } else if (method_exists($resource, 'toArray')) {
                $resourceData = $resource->toArray(request());
            }
        }

        $resourceData = ResourceLifecycleEvent::transformResourceChildrenToId($resourceData);

        $data = [
            'id' => uniqid('event_'),
            'api_version' => config('api.version'),
            'event' => 'order.ping',
            'created_at' => now()->toDateTimeString(),
            'data' => $resourceData,
        ];

        return new BroadcastMessage($data);
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toFcm($notifiable)
    {
        $notification = \NotificationChannels\Fcm\Resources\Notification::create()
            ->setTitle('New incoming order!')
            ->setBody($this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.');

        $message = FcmMessage::create()
            ->setData(['id' => $this->order->public_id, 'type' => 'order_ping'])
            ->setNotification($notification)
            ->setAndroid(
                AndroidConfig::create()
                    ->setFcmOptions(AndroidFcmOptions::create()->setAnalyticsLabel('analytics'))
                    ->setNotification(AndroidNotification::create()->setColor('#4391EA'))
            )->setApns(
                ApnsConfig::create()
                    ->setFcmOptions(ApnsFcmOptions::create()->setAnalyticsLabel('analytics_ios'))
            );

        return $message;
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toApn($notifiable)
    {
        $message = ApnMessage::create()
            ->badge(1)
            ->title('New incoming order!')
            ->body($this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.')
            ->custom('type', 'order_ping')
            ->custom('id', $this->order->public_id)
            ->action('view_order', ['id' => $this->order->public_id]);

        return $message;
    }
}
