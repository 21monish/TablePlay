@props(['value', 'label' => null])
@php($normalized = strtolower(str_replace([' ', '_'], '-', $value)))
<span {{ $attributes->class(['status-badge', 'status-badge--'.$normalized]) }}><i></i>{{ $label ?: str($value)->replace('_', ' ')->title() }}</span>
