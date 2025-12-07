# AI Ticket Classifier for osTicket

Automatically classify support tickets using AI (OpenAI or Anthropic). The plugin assigns priority, topic/category, and fills custom form fields based on ticket content.

## Features

- **Automatic classification** on ticket creation
- **Reclassification** when customers send new messages (optional)
- **Manual classification** via "Classify with AI" button in ticket view
- **Multiple AI providers**: OpenAI (GPT-4o, GPT-4o-mini, etc.) and Anthropic (Claude)
- **Custom field support**: Text, memo, choice, and boolean fields
- **Configurable options**: Choose which fields the AI can modify

## Requirements

- osTicket 1.18+
- PHP 7.4+ with cURL extension
- OpenAI or Anthropic API key

## Installation

1. Download or clone this repository
2. Copy the `ai-ticket-classifier` folder to `<osticket>/include/plugins/`
3. Go to **Admin Panel > Manage > Plugins**
4. Click **Add New Plugin**
5. Select **AI Ticket Classifier** and click **Install**
6. Click on the plugin name to configure

## Configuration

### AI Provider Settings

| Setting | Description |
|---------|-------------|
| **AI Provider** | Choose between OpenAI or Anthropic |
| **API Key** | Your API key for the selected provider |
| **Model** | Model to use (e.g., `gpt-4o-mini`, `claude-3-haiku-20240307`) |

### Classification Triggers

| Setting | Description |
|---------|-------------|
| **Classify New Tickets** | Automatically classify tickets when created |
| **Reclassify on Customer Reply** | Reclassify when customer sends a new message |

### Classification Options

| Setting | Description |
|---------|-------------|
| **Set Priority** | Allow AI to set ticket priority |
| **Set Topic/Category** | Allow AI to set ticket topic |

### AI-Managed Custom Fields

Select which custom fields from the Ticket Details form the AI should fill. Supported field types:
- Text
- Memo (long text)
- Choices (dropdown/select)
- Boolean (checkbox)

### Error Handling

| Setting | Description |
|---------|-------------|
| **On API Error** | Log to System Logs or Silent |
| **Debug Logging** | Enable verbose logging (disable in production) |

### Advanced Settings

| Setting | Description |
|---------|-------------|
| **Temperature** | AI randomness (0-2). Note: GPT-5 and o-series only support 1 |
| **API Timeout** | Maximum seconds to wait for AI response |

## Usage

### Automatic Classification

When enabled, tickets are automatically classified:
- On creation (if "Classify New Tickets" is enabled)
- On customer reply (if "Reclassify on Customer Reply" is enabled)

### Manual Classification

1. Open a ticket in the Staff Panel
2. Click the **More** dropdown menu
3. Select **Classify with AI**
4. The page will reload with updated classification

## Recommended Models

### OpenAI
- `gpt-4o-mini` - Fast and cost-effective (recommended)
- `gpt-4o` - More capable, higher cost

### Anthropic
- `claude-3-haiku-20240307` - Fast and cost-effective
- `claude-3-5-sonnet-20241022` - More capable

## Troubleshooting

### Classification not working

1. Check that the API key is correct
2. Enable **Debug Logging** in plugin settings
3. Check **Admin Panel > Dashboard > System Logs** for errors

### Custom fields not being filled

1. Ensure the field is selected in **AI-Managed Custom Fields**
2. Check that the field type is supported (text, memo, choices, bool)
3. Enable debug logging to see detailed information

### API errors

- **401 Unauthorized**: Invalid API key
- **429 Too Many Requests**: Rate limit exceeded
- **500/503**: Provider service issues

## Related Plugins

This plugin works great in combination with [AI Response Generator](https://github.com/istoutjesdijk/ai-response-generator) - automatically generate professional responses to tickets using AI. Together they provide a complete AI-powered ticket handling solution:

1. **AI Ticket Classifier** - Automatically categorizes and prioritizes incoming tickets
2. **AI Response Generator** - Suggests or generates responses for agents

## License

MIT License

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/istoutjesdijk/ai-ticket-classifier/issues).
