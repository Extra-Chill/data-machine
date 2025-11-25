# ColorManager System

Centralized color management system for Calendar block display styles with CSS custom properties support.

## Overview

ColorManager provides a unified color theming system for the Calendar block, enabling consistent color management across different display modes. Features dynamic color generation, CSS custom property integration, and JavaScript-based color resolution.

## Features

### CSS Custom Properties
- **Dynamic Color Variables**: Automatic CSS custom property generation
- **Fill/Stroke Support**: Separate variables for fills and strokes
- **Fallback Values**: Graceful degradation with default colors
- **Day-based Colors**: Unique colors for each day of the week

### JavaScript Integration
- **Runtime Color Resolution**: Dynamic color value computation
- **Browser Compatibility**: Cross-browser color handling
- **Performance Optimized**: Efficient color caching and reuse
- **Element Integration**: Direct color application to DOM elements

### Color Generation
- **Hash-based Colors**: Consistent color generation from taxonomy terms
- **Day Color Mapping**: Predefined color palette for weekdays
- **Automatic Variants**: RGB and RGBA color variants
- **Theme Integration**: Seamless integration with WordPress theme colors

## Usage

### JavaScript API

#### Basic Color References
```javascript
import { ColorManager } from './ColorManager.js';

// Get fill variable for a day
const fillVar = ColorManager.getFillVar('monday');
// Returns: "var(--datamachine-day-monday-rgba, rgba(0,0,0,0.03))"

// Get stroke variable for a day
const strokeVar = ColorManager.getStrokeVar('tuesday');
// Returns: "var(--datamachine-day-tuesday, var(--datamachine-border-default))"
```

#### Computed Color Values
```javascript
// Resolve actual color value
const computedColor = ColorManager.getComputedVar('--datamachine-day-monday');
// Returns: "#ff6b6b" (actual computed value)

// Apply colors to elements
ColorManager.applyToElement(element, 'monday', {
    fill: true,
    stroke: true
});
```

### CSS Integration

#### Root CSS Variables
```css
:root {
    /* Day color definitions */
    --datamachine-day-sunday: #ff6b6b;
    --datamachine-day-sunday-rgb: 255, 107, 107;
    --datamachine-day-sunday-rgba: rgba(255, 107, 107, 0.15);
    
    --datamachine-day-monday: #4ecdc4;
    --datamachine-day-monday-rgb: 78, 205, 196;
    --datamachine-day-monday-rgba: rgba(78, 205, 196, 0.15);
    
    /* Default fallback colors */
    --datamachine-border-default: #e1e5e9;
    --datamachine-background-default: rgba(0, 0, 0, 0.03);
}
```

#### Usage in Components
```css
.circuit-grid-badge {
    background: var(--datamachine-day-monday-rgba, rgba(0,0,0,0.03));
    border: 2px solid var(--datamachine-day-monday, var(--datamachine-border-default));
}
```

## Architecture

### ColorManager Class Structure
```javascript
export const ColorManager = {
    // CSS variable references
    getFillVar(dayName),
    getStrokeVar(dayName),
    
    // Computed values
    getComputedVar(propertyName),
    getComputedFillVar(dayName),
    getComputedStrokeVar(dayName),
    
    // Element manipulation
    applyToElement(element, dayName, options),
    
    // Utility methods
    isValidDayName(dayName),
    getFallbackColor(type)
};
```

### Integration Points

#### BadgeRenderer Integration
```javascript
// In BadgeRenderer.js
import { ColorManager } from './ColorManager.js';

class BadgeRenderer {
    renderBadge(dayName, element) {
        const fillVar = ColorManager.getFillVar(dayName);
        const strokeVar = ColorManager.getStrokeVar(dayName);
        
        element.style.setProperty('background', fillVar);
        element.style.setProperty('border-color', strokeVar);
    }
}
```

#### CircuitGridRenderer Integration
```javascript
// In CircuitGridRenderer.js
import { ColorManager } from './ColorManager.js';

class CircuitGridRenderer {
    applyDayColors(dayGroups) {
        dayGroups.forEach(group => {
            ColorManager.applyToElement(group.element, group.dayName, {
                fill: true,
                stroke: true
            });
        });
    }
}
```

## Color System

### Day Color Palette
- **Sunday**: #ff6b6b (Warm red)
- **Monday**: #4ecdc4 (Calm teal)
- **Tuesday**: #45b7d1 (Sky blue)
- **Wednesday**: #96ceb4 (Sage green)
- **Thursday**: #feca57 (Warm yellow)
- **Friday**: #d63384 (Deep pink)
- **Saturday**: #6c5ce7 (Soft purple)

### Color Variants
- **Base Color**: Full opacity for borders and strokes
- **RGB Values**: Comma-separated for RGBA generation
- **RGBA Values**: 15% opacity for backgrounds and fills
- **Fallback Colors**: Neutral defaults for missing values

## Performance Features

### Efficient Color Resolution
- **CSS Custom Properties**: Browser-native color management
- **Computed Value Caching**: Avoid repeated DOM queries
- **Batch Operations**: Efficient multi-element color application
- **Memory Management**: Minimal memory footprint

### Browser Optimization
- **Hardware Acceleration**: GPU-accelerated color transitions
- **Reduced Reflows**: Optimized DOM manipulation
- **CSS Engine Integration**: Leverages browser CSS optimization
- **Cross-browser Support**: Consistent behavior across browsers

## Customization

### Extending Color Palette
```javascript
// Add custom day colors
add_filter('datamachine_events_day_colors', function(colors) {
    colors['custom-day'] = '#ff9ff3';
    return colors;
});
```

### Custom Color Variables
```css
/* Theme integration */
:root {
    --datamachine-day-sunday: var(--theme-primary-color, #ff6b6b);
    --datamachine-day-monday: var(--theme-secondary-color, #4ecdc4);
}
```

### Dynamic Color Generation
```javascript
// Generate colors from taxonomy terms
function generateTaxonomyColor(termName) {
    const hash = hashCode(termName);
    const hue = hash % 360;
    return `hsl(${hue}, 70%, 60%)`;
}
```

## Integration Examples

### Taxonomy Badge Colors
```javascript
// Apply taxonomy colors to badges
function applyTaxonomyColors(badges) {
    badges.forEach(badge => {
        const dayName = getDayFromTaxonomy(badge.taxonomy);
        ColorManager.applyToElement(badge.element, dayName, {
            fill: true,
            stroke: true
        });
    });
}
```

### Circuit Grid Styling
```javascript
// Dynamic circuit grid coloring
class CircuitGridStyler {
    updateGridColors(dayGroups) {
        dayGroups.forEach(group => {
            const elements = group.getElements();
            elements.forEach(element => {
                ColorManager.applyToElement(element, group.dayName, {
                    fill: group.hasEvents,
                    stroke: true
                });
            });
        });
    }
}
```

## Troubleshooting

### Common Issues
- **Color Not Applying**: Verify CSS custom properties are defined in :root
- **Fallback Colors**: Ensure default colors are properly set
- **Browser Compatibility**: Test color rendering across target browsers
- **Performance**: Monitor color resolution performance with large datasets

### Debug Information
```javascript
// Debug color resolution
console.log('Available colors:', ColorManager.getAvailableColors());
console.log('Computed value:', ColorManager.getComputedVar('--datamachine-day-monday'));
console.log('Element colors:', ColorManager.getElementColors(element));
```

The ColorManager system provides comprehensive color management for the Calendar block with seamless integration across CSS and JavaScript, ensuring consistent theming and optimal performance.