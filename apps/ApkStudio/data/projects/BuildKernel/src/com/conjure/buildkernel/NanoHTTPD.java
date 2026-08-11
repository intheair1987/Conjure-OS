package com.conjure.buildkernel;

import java.io.*;
import java.net.*;
import java.util.*;

public class NanoHTTPD {
    private final int port;
    private ServerSocket serverSocket;
    private boolean isRunning;
    private final OnRequestCallback callback;

    public interface OnRequestCallback {
        void handleRequest(Socket socket, String method, String uri, Map<String, String> queryParams, InputStream bodyStream) throws Exception;
    }

    public NanoHTTPD(int port, OnRequestCallback callback) {
        this.port = port;
        this.callback = callback;
    }

    public void start() throws IOException {
        serverSocket = new ServerSocket(port);
        isRunning = true;
        new Thread(new Runnable() {
            @Override
            public void run() {
                while (isRunning) {
                    try {
                        final Socket socket = serverSocket.accept();
                        new Thread(new Runnable() {
                            @Override
                            public void run() {
                                try {
                                    handleClient(socket);
                                } catch (Exception e) {
                                    e.printStackTrace();
                                } finally {
                                    try {
                                        if (!socket.isClosed()) socket.close();
                                    } catch (Exception e) {
                                        e.printStackTrace();
                                    }
                                }
                            }
                        }).start();
                    } catch (IOException e) {
                        if (!isRunning) break;
                    }
                }
            }
        }).start();
    }

    public void stop() {
        isRunning = false;
        if (serverSocket != null) {
            try {
                serverSocket.close();
            } catch (IOException e) {
                e.printStackTrace();
            }
        }
    }

    private void handleClient(Socket socket) throws Exception {
        InputStream input = socket.getInputStream();
        OutputStream output = socket.getOutputStream();
        
        // Read Request Line
        ByteArrayOutputStream lineBuffer = new ByteArrayOutputStream();
        int b;
        while ((b = input.read()) != -1) {
            if (b == '\n') break;
            if (b != '\r') lineBuffer.write(b);
        }
        
        String requestLine = lineBuffer.toString("UTF-8");
        if (requestLine.isEmpty()) return;
        
        String[] requestParts = requestLine.split(" ");
        if (requestParts.length < 3) return;
        
        String method = requestParts[0];
        String rawUri = requestParts[1];
        
        // Instant CORS Preflight Handler
        if (method.equalsIgnoreCase("OPTIONS")) {
            sendCorsResponse(output, "204 No Content", null);
            return;
        }

        // Parse query parameters
        String uri = rawUri;
        Map<String, String> queryParams = new HashMap<>();
        int qIdx = rawUri.indexOf('?');
        if (qIdx != -1) {
            uri = rawUri.substring(0, qIdx);
            String qStr = rawUri.substring(qIdx + 1);
            for (String param : qStr.split("&")) {
                String[] pair = param.split("=");
                if (pair.length == 2) {
                    queryParams.put(
                        URLDecoder.decode(pair[0], "UTF-8"), 
                        URLDecoder.decode(pair[1], "UTF-8")
                    );
                } else if (pair.length == 1) {
                    queryParams.put(
                        URLDecoder.decode(pair[0], "UTF-8"), 
                        ""
                    );
                }
            }
        }
        
        // Read headers
        int contentLength = 0;
        while (true) {
            lineBuffer.reset();
            while ((b = input.read()) != -1) {
                if (b == '\n') break;
                if (b != '\r') lineBuffer.write(b);
            }
            String headerLine = lineBuffer.toString("UTF-8");
            if (headerLine.isEmpty()) break;
            
            if (headerLine.toLowerCase().startsWith("content-length:")) {
                contentLength = Integer.parseInt(headerLine.substring(15).trim());
            }
        }
        
        InputStream limitedInput = new BoundedInputStream(input, contentLength);
        callback.handleRequest(socket, method, uri, queryParams, limitedInput);
    }

    private void sendCorsResponse(OutputStream out, String status, String contentType) throws IOException {
        out.write(("HTTP/1.1 " + status + "\r\n").getBytes());
        out.write("Access-Control-Allow-Origin: *\r\n".getBytes());
        out.write("Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n".getBytes());
        out.write("Access-Control-Allow-Headers: Content-Type, Content-Length\r\n".getBytes());
        if (contentType != null) {
            out.write(("Content-Type: " + contentType + "\r\n").getBytes());
        }
        out.write("Connection: close\r\n\r\n".getBytes());
        out.flush();
    }

    private static class BoundedInputStream extends InputStream {
        private final InputStream in;
        private final long limit;
        private long total = 0;

        public BoundedInputStream(InputStream in, long limit) {
            this.in = in;
            this.limit = limit;
        }

        @Override
        public int read() throws IOException {
            if (total >= limit) return -1;
            int result = in.read();
            if (result != -1) total++;
            return result;
        }

        @Override
        public int read(byte[] b, int off, int len) throws IOException {
            if (total >= limit) return -1;
            long maxToRead = Math.min(len, limit - total);
            int result = in.read(b, off, (int) maxToRead);
            if (result != -1) total += result;
            return result;
        }
    }
}