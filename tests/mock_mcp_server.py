"""Local fake MCP server for integration tests; no Unreal required."""
from mcp.server.fastmcp import FastMCP

server = FastMCP('bridge-test')

@server.tool()
def echo_message(message: str) -> str:
    return 'echo: ' + message

if __name__ == '__main__':
    server.run(transport='stdio')
