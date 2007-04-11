/*
 * UDPNetworkListener.java
 *
 * Created on November 25, 2006, 3:25 PM
 *
 * To change this template, choose Tools | Template Manager
 * and open the template in the editor.
 */
        
package dadsyslog;

import java.io.*;
import java.net.*;

/**
 *
 * @author dhoelzer
 */
public class UDPNetworkListener {
    
    private int iPortNumber;
    private DataLogger dlo;
    private String sServiceName;
    private boolean bMessageWaiting;
    private DatagramPacket dpPacket;
    private byte[] bBuffer = new byte[4096];
    private DatagramSocket dsSocket;
    private int SocketTimeout;
    
    public void SetSocketTimeout(int Timeout)
    {
        /*
         * Sets the timeout for the read operation on the UDP port.
         * This number should be greater than zero.  The default is
         * 100.
         */
        SocketTimeout = Timeout;
    }
    /** Creates a new instance of UDPNetworkListener */
    public UDPNetworkListener(String lsServiceName, int liPortNumber, DataLogger LogObject) 
    {
        SetPort(liPortNumber);
        SetServiceName(lsServiceName);
        SetLogger(LogObject);
        SetSocketTimeout(100);
        StartLogger();
    }
    
    public void SetLogger(DataLogger LogObject)
    {
        dlo = LogObject;
    }
    
    public String GetServiceName()
    {
        return sServiceName;
    }
    
    public void SetServiceName(String lsServiceName)
    {
        if(lsServiceName.length() > 0)
        {
            sServiceName = lsServiceName;
            return;
        }
        System.out.println("Invalid or Empty Service Name: "+lsServiceName);
        sServiceName = "Empty";
    }
    
    public int GetPort()
    {
        return iPortNumber;
    }
    
    private void SetPort(int iPort)
    {
        if(iPort > 0 && iPort < 65535)
        {
            iPortNumber = iPort;
        }
        else
        {
            System.out.println("Invalid port number: "+iPort+".  SetPort ignored.");
        }
    }
    
    private void StartLogger()
    {
        boolean LogSuccess;
        
        dpPacket = new DatagramPacket(bBuffer, bBuffer.length);
        try
        {
            dsSocket = new DatagramSocket(GetPort());
            dsSocket.setSoTimeout(SocketTimeout);
            while(true)
            {
                try 
                {
                    dsSocket.receive(dpPacket);
                    String lsMessage = new String(bBuffer, 0, 0, dpPacket.getLength());
                    LogSuccess = dlo.LogData(dpPacket.getSocketAddress()+" "+lsMessage);
                    if(!LogSuccess)
                    {
                        System.out.println("Log subsystem failure!");
                        return;
                    }
                }
                catch (IOException se)
                {
                    ;  /* 
                        * We don't care.
                        * The purpose of this is to allow the Scheduler to send signals
                        * to the process that are actually handled without long term blocking.
                        */
                }
            }
        }
        catch (SocketException eSE)
        {
            System.out.println("Socket Exception: "+eSE.getMessage());
        }
        catch (IOException eIOE)
        {
            System.out.println("IO Exception: "+eIOE.getMessage());
        }
    }
}