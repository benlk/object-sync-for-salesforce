# Extending scheduling

This plugin starts with an array of what classes can be scheduled with the [`schedule`](../classes/schedule.php) class.

## Default:

```
$this->schedulable_classes = array(
    'salesforce_push' => array(
        'label' => 'Push to Salesforce',
        'class' => 'Salesforce_Push',
        'callback' => 'salesforce_push_sync_rest',
    ),
    'salesforce_pull' => array(
        'label' => 'Pull from Salesforce',
        'class' => 'Salesforce_Pull',
        'initializer' => 'salesforce_pull',
        'callback' => 'salesforce_pull_process_records'
    )
);
```

This defines the `push` and `pull` classes as schedulable items, and sets the methods (`salesforce_push_sync_rest` and `salesforce_pull_process_records`) that run when the schedule events happen. This is how the plugin sends data between the two systems at regular intervals, as documented in the [syncing setup](./syncing-setup.md) documentation.

## Hook

If you'd like to schedule other things to happen with this plugin, or remove defaults from the schedule, you can do that with the `object_sync_for_salesforce_modify_schedulable_classes` hook.

### Code example

```
// example to modify the array of classes by adding one and removing one
add_filter( 'object_sync_for_salesforce_modify_schedulable_classes', 'modify_schedulable_classes', 10, 1 );
function modify_schedulable_classes( $schedulable_classes ) {
    $schedulable_classes = array(
        array(
            'name' => 'salesforce_push',
            'label' => 'Push to Salesforce'
        ),
        array(
            'name' => 'wordpress',
            'label' => 'WordPress'
        )
    );
    return $schedulable_classes;
}
```